/* =========================================================================
   VISITOR STAY DURATION REMINDER & OVERSTAY ALARM CONTROLLER
   -------------------------------------------------------------------------
   Provides:
   - Tailored multi-stage reminders for EVERY visitor stay duration:
       * Drop - off/on (15 Mins)       -> Reminders at 5m, 2m | Alarm at 0:00
       * Delivery (20 Mins)            -> Reminders at 5m, 2m | Alarm at 0:00
       * Service Maintenance (8 Hours) -> Reminders at 1h, 30m, 15m, 5m | Alarm at 0:00
       * Birthday (12 Hours)           -> Reminders at 1h, 30m, 15m, 5m | Alarm at 0:00
       * Visiting Resident (12 Hours)  -> Reminders at 1h, 30m, 15m, 5m | Alarm at 0:00
       * Custom Durations              -> Proportional reminders + Overstay Alarm
   - Smooth 1-second synchronized live countdowns across banners, cards & tables
   - 3 distinct Web Audio synthesized tones (Reminder Chime, Final Warning,
     and Repeating Overstay Siren) with Mute/Unmute, 5-Min Snooze & Test Audio
   - Interactive Active Visitor Timers Drawer (+15m/+30m Extend & Mark OUT)
   ========================================================================= */

(function () {
    "use strict";

    if (window.VisitorDurationAlarm) {
        return;
    }

    var MUTE_STORAGE_KEY   = "guard_alarm_muted";
    var STAGE_STORAGE_KEY  = "vda_notified_stages_v1";
    var SNOOZE_STORAGE_KEY = "vda_snoozed_until_v1";
    var DISMISS_BANNER_KEY = "vda_dismissed_banner_stage_v1";

    var OVERSTAY_REPEAT_MS = 20000; // Repeat overstay alarm every 20s while overdue
    var POLL_INTERVAL_MS   = 3000;  // Standalone poll interval on non-dashboard pages

    var serverClockOffsetMs = 0;
    var activeTimers        = [];
    var pendingVisitors     = [];
    var timersByPlateNorm   = {};
    var pendingByPlateNorm  = {};

    var notifiedStages      = loadJsonMap(STAGE_STORAGE_KEY, sessionStorage);
    var snoozedUntilMap     = loadJsonMap(SNOOZE_STORAGE_KEY, sessionStorage);
    var dismissedBannerKey  = sessionStorage.getItem(DISMISS_BANNER_KEY) || "";

    var alarmMuted          = localStorage.getItem(MUTE_STORAGE_KEY) === "true";
    var lastOverstaySoundAt = 0;
    var sharedAudioCtx      = null;
    var externalFeedActive  = false;
    var pollTimerId         = null;
    var isFetchingApi       = false;

    /* ---------------------------------------------------------------------
       DURATION MILESTONE RULES (MIRRORS app/system_config.php)
    --------------------------------------------------------------------- */
    var PRESET_DEFINITIONS = {
        "Drop - off/on - 15 Mins": {
            label: "Drop - off/on",
            minutes: 15,
            durationLabel: "15 Mins",
            milestones: [5, 2]
        },
        "Delivery - 20 Mins": {
            label: "Delivery",
            minutes: 20,
            durationLabel: "20 Mins",
            milestones: [5, 2]
        },
        "Service Maintenance - 8 Hours": {
            label: "Service Maintenance",
            minutes: 480,
            durationLabel: "8 Hours",
            milestones: [60, 30, 15, 5]
        },
        "Birthday - 12 Hours": {
            label: "Birthday",
            minutes: 720,
            durationLabel: "12 Hours",
            milestones: [60, 30, 15, 5]
        },
        "Visiting Resident - 12 Hours": {
            label: "Visiting Resident",
            minutes: 720,
            durationLabel: "12 Hours",
            milestones: [60, 30, 15, 5]
        }
    };

    function getMilestonesForMinutes(mins) {
        mins = Math.max(1, parseInt(mins || 15, 10));
        if (mins <= 2) return [1];
        if (mins <= 5) return [2, 1];
        if (mins <= 14) return [3, 1];
        if (mins <= 30) return [5, 2];
        if (mins <= 90) return [15, 5, 2];
        if (mins <= 240) return [30, 15, 5];
        return [60, 30, 15, 5];
    }

    function formatMinutesHuman(mins) {
        mins = Math.max(1, parseInt(mins || 1, 10));
        if (mins >= 60 && mins % 60 === 0) {
            var h = Math.floor(mins / 60);
            return h + (h === 1 ? " Hour" : " Hours");
        }
        if (mins > 60) {
            var hrs = Math.floor(mins / 60);
            var rem = mins % 60;
            return hrs + "h " + rem + "m";
        }
        return mins + (mins === 1 ? " Min" : " Mins");
    }

    function formatMilestoneChip(m) {
        if (m >= 60 && m % 60 === 0) {
            return (m / 60) + "h";
        }
        return m + "m";
    }

    function getDurationScheduleInfo(purposeValue, customMinutes) {
        var p = String(purposeValue || "").trim();
        if (p === "__CUSTOM__" || (customMinutes && parseInt(customMinutes, 10) > 0)) {
            var cMins = Math.max(1, parseInt(customMinutes || 30, 10));
            var cMs = getMilestonesForMinutes(cMins);
            return {
                minutes: cMins,
                durationLabel: formatMinutesHuman(cMins),
                milestones: cMs,
                scheduleText: "Reminders at " + cMs.map(formatMilestoneChip).join(", ") + " left • Alarm at " + formatMinutesHuman(cMins)
            };
        }
        if (PRESET_DEFINITIONS[p]) {
            var pr = PRESET_DEFINITIONS[p];
            return {
                minutes: pr.minutes,
                durationLabel: pr.durationLabel,
                milestones: pr.milestones.slice(),
                scheduleText: "Reminders at " + pr.milestones.map(formatMilestoneChip).join(", ") + " left • Alarm at " + pr.durationLabel
            };
        }
        var mins = 60;
        var mHr = p.match(/(\d+)\s*(hour|hr|hrs|hours)\b/i);
        var mMin = p.match(/(\d+)\s*(min|mins|minute|minutes)\b/i);
        if (mHr) {
            mins = Math.max(1, parseInt(mHr[1], 10) * 60);
        } else if (mMin) {
            mins = Math.max(1, parseInt(mMin[1], 10));
        } else if (/drop/i.test(p)) {
            mins = 15;
        } else if (/delivery/i.test(p)) {
            mins = 20;
        } else if (/service|maintenance/i.test(p)) {
            mins = 480;
        } else if (/birthday|visiting/i.test(p)) {
            mins = 720;
        }
        var ms = getMilestonesForMinutes(mins);
        return {
            minutes: mins,
            durationLabel: formatMinutesHuman(mins),
            milestones: ms,
            scheduleText: "Reminders at " + ms.map(formatMilestoneChip).join(", ") + " left • Alarm at " + formatMinutesHuman(mins)
        };
    }

    function evaluateLiveTimer(allowedMinutes, secondsRemaining) {
        var mins = Math.max(1, parseInt(allowedMinutes || 15, 10));
        var milestones = getMilestonesForMinutes(mins);
        var finalMilestone = milestones.length ? milestones[milestones.length - 1] : 1;

        if (secondsRemaining <= 0) {
            var secOver = Math.abs(secondsRemaining);
            var minOver = Math.max(1, Math.ceil(Math.max(1, secOver) / 60));
            var bucket = Math.floor(Math.floor(secOver / 60) / 5) * 5;
            return {
                state: "OVERDUE",
                stage_key: "overdue_" + bucket,
                active_milestone: 0,
                milestones: milestones,
                seconds_remaining: secondsRemaining,
                minutes_left: 0,
                minutes_overdue: minOver,
                seconds_overdue: secOver
            };
        }

        var minutesLeft = Math.ceil(secondsRemaining / 60);
        var matched = null;
        for (var i = milestones.length - 1; i >= 0; i--) {
            if (secondsRemaining <= milestones[i] * 60) {
                matched = milestones[i];
                break;
            }
        }

        if (matched !== null) {
            var isFinal = (matched === finalMilestone);
            return {
                state: isFinal ? "FINAL_WARNING" : "REMINDER",
                stage_key: (isFinal ? "final_" : "rem_") + matched + "m",
                active_milestone: matched,
                milestones: milestones,
                seconds_remaining: secondsRemaining,
                minutes_left: minutesLeft,
                minutes_overdue: 0,
                seconds_overdue: 0
            };
        }

        return {
            state: "ACTIVE",
            stage_key: "active",
            active_milestone: null,
            milestones: milestones,
            seconds_remaining: secondsRemaining,
            minutes_left: minutesLeft,
            minutes_overdue: 0,
            seconds_overdue: 0
        };
    }

    /* ---------------------------------------------------------------------
       STORAGE & FORMATTING HELPERS
    --------------------------------------------------------------------- */
    function loadJsonMap(key, store) {
        try {
            var raw = store.getItem(key);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveJsonMap(key, mapObj, store) {
        try {
            var keys = Object.keys(mapObj);
            if (keys.length > 300) {
                var trimmed = {};
                keys.slice(keys.length - 200).forEach(function (k) {
                    trimmed[k] = mapObj[k];
                });
                mapObj = trimmed;
            }
            store.setItem(key, JSON.stringify(mapObj));
        } catch (e) {}
    }

    function normalizePlate(plate) {
        return String(plate || "").toUpperCase().replace(/\s+/g, "").trim();
    }

    function escapeHtml(str) {
        var div = document.createElement("div");
        div.textContent = str == null ? "" : String(str);
        return div.innerHTML;
    }

    function pad2(n) {
        return n < 10 ? "0" + n : String(n);
    }

    function formatCountdownClock(seconds) {
        var absSec = Math.max(0, Math.floor(Math.abs(seconds)));
        var hrs = Math.floor(absSec / 3600);
        var mins = Math.floor((absSec % 3600) / 60);
        var secs = absSec % 60;
        if (hrs > 0) {
            return pad2(hrs) + ":" + pad2(mins) + ":" + pad2(secs);
        }
        return pad2(mins) + ":" + pad2(secs);
    }

    function detectPageRole() {
        if (window.SECSYS_ALARM_ROLE) {
            return window.SECSYS_ALARM_ROLE;
        }
        var p = window.location.pathname.toLowerCase();
        if (
            p.indexOf("superadmin") !== -1 ||
            p.indexOf("/visitor.php") !== -1 ||
            p.indexOf("/registered.php") !== -1 ||
            p.indexOf("/logs.php") !== -1
        ) {
            return "Administrator";
        }
        return "Security Guard";
    }

    function nowServerSeconds() {
        return Math.floor((Date.now() + serverClockOffsetMs) / 1000);
    }

    function isVisitorSnoozed(visitorId) {
        var until = snoozedUntilMap[String(visitorId)] || 0;
        return until > Date.now();
    }

    /* ---------------------------------------------------------------------
       WEB AUDIO SYNTHESIZER (REMINDER CHIME, FINAL WARNING & OVERSTAY SIREN)
    --------------------------------------------------------------------- */
    function getAudioContext() {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return null;
        if (!sharedAudioCtx) {
            try {
                sharedAudioCtx = new AudioCtx();
            } catch (e) {
                return null;
            }
        }
        if (sharedAudioCtx && sharedAudioCtx.state === "suspended") {
            sharedAudioCtx.resume().catch(function () {});
        }
        return sharedAudioCtx;
    }

    function unlockAudioOnGesture() {
        if (sharedAudioCtx && sharedAudioCtx.state === "suspended") {
            sharedAudioCtx.resume().catch(function () {});
        } else if (!sharedAudioCtx) {
            getAudioContext();
        }
    }

    document.addEventListener("click", unlockAudioOnGesture, { passive: true });
    document.addEventListener("keydown", unlockAudioOnGesture, { passive: true });

    function scheduleNote(ctx, type, freq, startOffset, duration, peakGain) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, ctx.currentTime + startOffset);
        gain.gain.setValueAtTime(0.001, ctx.currentTime + startOffset);
        gain.gain.linearRampToValueAtTime(peakGain, ctx.currentTime + startOffset + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + startOffset + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime + startOffset);
        osc.stop(ctx.currentTime + startOffset + duration + 0.02);
    }

    function playReminderSound(force) {
        if (alarmMuted && !force) return;
        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            // Pleasant two-note ascending chime (C5 -> G5)
            scheduleNote(ctx, "sine", 523.25, 0.00, 0.22, 0.20);
            scheduleNote(ctx, "sine", 783.99, 0.18, 0.35, 0.22);
        } catch (e) {}
    }

    function playFinalWarningSound(force) {
        if (alarmMuted && !force) return;
        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            // Crisp 3-pulse amber warning chime (E5 -> E5 -> B5)
            scheduleNote(ctx, "triangle", 659.25, 0.00, 0.16, 0.24);
            scheduleNote(ctx, "triangle", 659.25, 0.20, 0.16, 0.24);
            scheduleNote(ctx, "triangle", 987.77, 0.40, 0.32, 0.28);
        } catch (e) {}
    }

    function playOverstayAlarmSound(force) {
        if (alarmMuted && !force) return;
        var now = Date.now();
        if (!force && (now - lastOverstaySoundAt < OVERSTAY_REPEAT_MS)) {
            return;
        }
        lastOverstaySoundAt = now;

        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            // Urgent alternating dual-tone security overstay alarm
            scheduleNote(ctx, "sawtooth", 880.00, 0.00, 0.18, 0.22);
            scheduleNote(ctx, "sawtooth", 659.25, 0.20, 0.18, 0.22);
            scheduleNote(ctx, "sawtooth", 880.00, 0.40, 0.18, 0.24);
            scheduleNote(ctx, "sawtooth", 659.25, 0.60, 0.28, 0.24);
        } catch (e) {}
    }

    /* ---------------------------------------------------------------------
       STYLES & UI INJECTION (BANNER, TOASTS, HEADER PILL & TIMERS DRAWER)
    --------------------------------------------------------------------- */
    function injectStyles() {
        if (document.getElementById("vdaStylesheet")) return;
        var style = document.createElement("style");
        style.id = "vdaStylesheet";
        style.textContent = [
            "/* Visitor Duration Reminder & Overstay Alarm Styles */",
            ".overstay-alert-banner {",
            "    border-radius: 12px;",
            "    padding: 12px 18px;",
            "    margin-bottom: 18px;",
            "    display: none;",
            "    align-items: center;",
            "    justify-content: space-between;",
            "    gap: 14px;",
            "    flex-wrap: wrap;",
            "    transition: background 0.25s ease, border-color 0.25s ease;",
            "}",
            ".overstay-alert-banner.vda-state-overdue {",
            "    background: #fff1f2 !important;",
            "    border: 1px solid #fecdd3 !important;",
            "    border-left: 6px solid #e11d48 !important;",
            "    box-shadow: 0 4px 14px rgba(225, 29, 72, 0.16) !important;",
            "    animation: vdaPulseBorder 1.8s infinite;",
            "}",
            ".overstay-alert-banner.vda-state-final {",
            "    background: #fff7ed !important;",
            "    border: 1px solid #fed7aa !important;",
            "    border-left: 6px solid #ea580c !important;",
            "    box-shadow: 0 3px 10px rgba(234, 88, 12, 0.13) !important;",
            "}",
            ".overstay-alert-banner.vda-state-reminder {",
            "    background: #fffbeb !important;",
            "    border: 1px solid #fde68a !important;",
            "    border-left: 6px solid #f59e0b !important;",
            "    box-shadow: 0 3px 10px rgba(245, 158, 11, 0.12) !important;",
            "}",
            "@keyframes vdaPulseBorder {",
            "    0%, 100% { box-shadow: 0 4px 14px rgba(225, 29, 72, 0.15); }",
            "    50% { box-shadow: 0 4px 20px rgba(225, 29, 72, 0.34); }",
            "}",
            ".overstay-alert-content { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }",
            ".overstay-alert-icon { font-size: 22px; flex-shrink: 0; }",
            ".overstay-alert-text { font-size: 13px; line-height: 1.45; color: #1f2937; }",
            ".vda-state-overdue .overstay-alert-text { color: #881337; }",
            ".vda-state-final .overstay-alert-text { color: #7c2d12; }",
            ".vda-state-reminder .overstay-alert-text { color: #78350f; }",
            ".overstay-alert-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }",
            ".vda-banner-clock {",
            "    display: inline-block; font-family: monospace; font-weight: 800; font-size: 13px;",
            "    padding: 2px 8px; border-radius: 6px; margin: 0 3px;",
            "}",
            ".vda-state-overdue .vda-banner-clock { background: #ffe4e6; color: #be123c; border: 1px solid #fda4af; }",
            ".vda-state-final .vda-banner-clock { background: #ffedd5; color: #c2410c; border: 1px solid #fdba74; }",
            ".vda-state-reminder .vda-banner-clock { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }",
            ".btn-vda-snooze {",
            "    background: #ffffff; border: 1px solid #94a3b8; color: #334155;",
            "    padding: 6px 11px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer;",
            "}",
            ".btn-vda-snooze:hover { background: #f1f5f9; }",
            ".btn-banner-mute {",
            "    background: #ffffff; border: 1px solid #cbd5e1; color: #1e293b;",
            "    padding: 6px 11px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer;",
            "}",
            ".btn-banner-mute:hover { background: #f8fafc; }",
            ".btn-view-overstay {",
            "    background: #e11d48; color: #ffffff; border: none; border-radius: 8px;",
            "    padding: 7px 13px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap;",
            "}",
            ".vda-state-final .btn-view-overstay { background: #ea580c; }",
            ".vda-state-reminder .btn-view-overstay { background: #d97706; }",
            ".btn-dismiss-alert {",
            "    background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer; padding: 2px 6px; line-height: 1;",
            "}",
            /* Header Timers Pill Button */
            ".btn-vda-timers {",
            "    position: relative; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 22px;",
            "    height: 38px; padding: 0 13px; display: inline-flex; align-items: center; gap: 7px;",
            "    cursor: pointer; font-family: Arial, sans-serif; font-size: 12px; font-weight: 700; color: #1e293b;",
            "    box-shadow: 0 1px 3px rgba(0,0,0,0.07); transition: all 0.18s ease; white-space: nowrap;",
            "}",
            ".btn-vda-timers:hover { background: #f1f5f9; border-color: #94a3b8; transform: translateY(-1px); }",
            ".btn-vda-timers.has-overdue { background: #fff1f2; border-color: #f43f5e; color: #be123c; animation: vdaPulseBorder 1.8s infinite; }",
            ".btn-vda-timers.has-warning { background: #fffbeb; border-color: #f59e0b; color: #b45309; }",
            ".vda-pill-count {",
            "    background: #e2e8f0; color: #334155; font-size: 11px; font-weight: 800;",
            "    padding: 1px 7px; border-radius: 999px; min-width: 20px; text-align: center;",
            "}",
            ".btn-vda-timers.has-overdue .vda-pill-count { background: #e11d48; color: #fff; }",
            ".btn-vda-timers.has-warning .vda-pill-count { background: #f59e0b; color: #fff; }",
            /* Inline countdown badges for cards & tables */
            ".vda-inline-timer {",
            "    display: inline-flex; align-items: center; gap: 4px; font-family: monospace;",
            "    font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px;",
            "    white-space: nowrap; vertical-align: middle; line-height: 1.35;",
            "}",
            ".vda-inline-timer.vda-t-active { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }",
            ".vda-inline-timer.vda-t-reminder { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }",
            ".vda-inline-timer.vda-t-final { background: #ffedd5; color: #c2410c; border: 1px solid #fdba74; }",
            ".vda-inline-timer.vda-t-overdue {",
            "    background: #fee2e2; color: #b91c1c; border: 1px solid #f87171;",
            "    animation: vdaBlinkSoft 1.2s infinite alternate;",
            "}",
            ".vda-inline-timer.vda-t-pending { background: #f1f5f9; color: #64748b; border: 1px dashed #cbd5e1; font-family: Arial, sans-serif; font-weight: 600; }",
            "@keyframes vdaBlinkSoft { from { opacity: 1; } to { opacity: 0.72; } }",
            /* Toast Container (for non-dashboard pages) */
            ".vda-toast-stack {",
            "    position: fixed; right: 20px; bottom: 20px; z-index: 100002;",
            "    display: flex; flex-direction: column; gap: 10px; max-width: 370px; pointer-events: none;",
            "}",
            ".vda-toast {",
            "    pointer-events: auto; background: #ffffff; border-radius: 12px; padding: 12px 15px;",
            "    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18); border: 1px solid #e2e8f0;",
            "    border-left: 5px solid #f59e0b; display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;",
            "    font-family: Arial, sans-serif; animation: vdaSlideIn 0.25s ease-out;",
            "}",
            ".vda-toast.toast-overdue { border-left-color: #e11d48; background: #fff1f2; }",
            ".vda-toast.toast-final { border-left-color: #ea580c; background: #fff7ed; }",
            "@keyframes vdaSlideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }",
            /* Active Timers Drawer */
            ".vda-drawer-overlay {",
            "    display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45);",
            "    backdrop-filter: blur(2px); z-index: 100000;",
            "}",
            ".vda-drawer-overlay.open { display: block; }",
            ".vda-drawer {",
            "    position: fixed; top: 0; right: -470px; width: 450px; max-width: 96vw; height: 100vh;",
            "    background: #f8fafc; z-index: 100001; box-shadow: -8px 0 28px rgba(15, 23, 42, 0.2);",
            "    display: flex; flex-direction: column; transition: right 0.26s ease; font-family: Arial, sans-serif;",
            "}",
            ".vda-drawer.open { right: 0; }",
            ".vda-drawer-header {",
            "    padding: 16px 20px; background: #ffffff; border-bottom: 1px solid #e2e8f0;",
            "    display: flex; align-items: center; justify-content: space-between;",
            "}",
            ".vda-drawer-title { font-size: 16px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; }",
            ".vda-drawer-toolbar {",
            "    padding: 10px 16px; background: #ffffff; border-bottom: 1px solid #e2e8f0;",
            "    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;",
            "}",
            ".vda-tool-btn {",
            "    background: #f1f5f9; border: 1px solid #cbd5e1; color: #334155; border-radius: 7px;",
            "    padding: 5px 10px; font-size: 11px; font-weight: 700; cursor: pointer;",
            "}",
            ".vda-tool-btn:hover { background: #e2e8f0; }",
            ".vda-drawer-guide {",
            "    padding: 10px 16px; background: #eff6ff; border-bottom: 1px solid #dbeafe;",
            "    font-size: 11px; color: #1e3a8a; line-height: 1.5;",
            "}",
            ".vda-drawer-body { flex: 1; overflow-y: auto; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }",
            ".vda-timer-card {",
            "    background: #ffffff; border: 1px solid #e2e8f0; border-left: 5px solid #22c55e;",
            "    border-radius: 12px; padding: 12px 14px; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.05);",
            "}",
            ".vda-timer-card.card-reminder { border-left-color: #f59e0b; background: #fffdf7; }",
            ".vda-timer-card.card-final { border-left-color: #ea580c; background: #fffaf5; }",
            ".vda-timer-card.card-overdue { border-left-color: #e11d48; background: #fff5f6; border-color: #fecdd3; }",
            ".vda-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 6px; }",
            ".vda-plate-chip {",
            "    font-family: monospace; font-size: 15px; font-weight: 800; color: #0f172a;",
            "    background: #f1f5f9; padding: 2px 8px; border-radius: 6px; border: 1px solid #cbd5e1;",
            "}",
            ".vda-clock-big { font-family: monospace; font-size: 16px; font-weight: 800; padding: 3px 9px; border-radius: 8px; }",
            ".card-overdue .vda-clock-big { background: #ffe4e6; color: #be123c; }",
            ".card-final .vda-clock-big { background: #ffedd5; color: #c2410c; }",
            ".card-reminder .vda-clock-big { background: #fef3c7; color: #b45309; }",
            ".vda-timer-card:not(.card-overdue):not(.card-final):not(.card-reminder) .vda-clock-big { background: #dcfce7; color: #15803d; }",
            ".vda-progress-track { width: 100%; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin: 8px 0; }",
            ".vda-progress-bar { height: 100%; border-radius: 999px; transition: width 0.6s linear; background: #22c55e; }",
            ".card-reminder .vda-progress-bar { background: #f59e0b; }",
            ".card-final .vda-progress-bar { background: #ea580c; }",
            ".card-overdue .vda-progress-bar { background: #e11d48; width: 100% !important; }",
            ".vda-milestones-row { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin-bottom: 8px; }",
            ".vda-ms-chip {",
            "    font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px;",
            "    background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0;",
            "}",
            ".vda-ms-chip.ms-passed { background: #fef3c7; color: #92400e; border-color: #fcd34d; }",
            ".vda-ms-chip.ms-active { background: #ea580c; color: #ffffff; border-color: #ea580c; }",
            ".vda-ms-chip.ms-alarm { background: #e11d48; color: #ffffff; border-color: #e11d48; }",
            ".vda-card-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; padding-top: 8px; border-top: 1px dashed #e2e8f0; }",
            ".vda-act-btn {",
            "    padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;",
            "    border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b;",
            "}",
            ".vda-act-btn:hover { background: #f1f5f9; }",
            ".vda-act-btn.act-extend { border-color: #93c5fd; color: #1d4ed8; background: #eff6ff; }",
            ".vda-act-btn.act-extend:hover { background: #dbeafe; }",
            ".vda-act-btn.act-out { border-color: #fca5a5; color: #b91c1c; background: #fef2f2; margin-left: auto; }",
            ".vda-act-btn.act-out:hover { background: #fee2e2; }",
            "@media print { .overstay-alert-banner, .btn-vda-timers, .vda-drawer, .vda-drawer-overlay, .vda-toast-stack { display: none !important; } }"
        ].join("\n");
        document.head.appendChild(style);
    }

    function ensureBannerAndControls() {
        // 1. Ensure top Overstay / Reminder Alert Banner exists on any monitoring page
        var banner = document.getElementById("overstayAlertBanner");
        if (!banner) {
            var mainEl = document.querySelector("main.main") || document.querySelector("main");
            if (mainEl) {
                banner = document.createElement("div");
                banner.className = "overstay-alert-banner";
                banner.id = "overstayAlertBanner";
                banner.style.display = "none";
                banner.innerHTML =
                    '<div class="overstay-alert-content">' +
                        '<span class="overstay-alert-icon" id="overstayAlertIcon">⚠️</span>' +
                        '<div class="overstay-alert-text" id="overstayAlertText"></div>' +
                    '</div>' +
                    '<div class="overstay-alert-actions">' +
                        '<button type="button" class="btn-vda-snooze" id="bannerSnoozeBtn" title="Snooze audio for 5 minutes">💤 Snooze 5m</button>' +
                        '<button type="button" class="btn-banner-mute" id="bannerMuteBtn">🔊 Mute Alarm</button>' +
                        '<button type="button" class="btn-view-overstay" id="bannerViewTimersBtn">⏱️ View Timers</button>' +
                        '<button type="button" class="btn-dismiss-alert" id="bannerDismissBtn" aria-label="Dismiss">&times;</button>' +
                    '</div>';
                var headerEl = mainEl.querySelector(".header");
                if (headerEl && headerEl.nextSibling) {
                    mainEl.insertBefore(banner, headerEl.nextSibling);
                } else {
                    mainEl.insertBefore(banner, mainEl.firstChild);
                }
            }
        } else {
            // Upgrade existing guard_dashboard.php banner with Snooze button & View Timers hook if missing
            var actions = banner.querySelector(".overstay-alert-actions");
            if (actions && !document.getElementById("bannerSnoozeBtn")) {
                var snoozeBtn = document.createElement("button");
                snoozeBtn.type = "button";
                snoozeBtn.className = "btn-vda-snooze";
                snoozeBtn.id = "bannerSnoozeBtn";
                snoozeBtn.title = "Snooze audio alarm for 5 minutes";
                snoozeBtn.textContent = "💤 Snooze 5m";
                actions.insertBefore(snoozeBtn, actions.firstChild);
            }
        }

        // Bind banner buttons cleanly
        var bSnooze = document.getElementById("bannerSnoozeBtn");
        if (bSnooze && !bSnooze.dataset.vdaBound) {
            bSnooze.dataset.vdaBound = "1";
            bSnooze.addEventListener("click", function () {
                snoozeAllUrgent(5);
            });
        }
        var bMute = document.getElementById("bannerMuteBtn");
        if (bMute && !bMute.dataset.vdaBound) {
            bMute.dataset.vdaBound = "1";
            bMute.removeAttribute("onclick");
            bMute.addEventListener("click", function () {
                toggleMute();
            });
        }
        var bView = banner ? banner.querySelector(".btn-view-overstay") : null;
        if (bView && !bView.dataset.vdaBound) {
            bView.dataset.vdaBound = "1";
            bView.removeAttribute("onclick");
            bView.textContent = "⏱️ View Timers";
            bView.addEventListener("click", function () {
                openTimersDrawer();
            });
        }
        var bDismiss = banner ? banner.querySelector(".btn-dismiss-alert") : null;
        if (bDismiss && !bDismiss.dataset.vdaBound) {
            bDismiss.dataset.vdaBound = "1";
            bDismiss.removeAttribute("onclick");
            bDismiss.addEventListener("click", function () {
                dismissCurrentBannerStage();
            });
        }

        // 2. Inject Header "⏱️ Timers" Button so user can inspect all durations anytime
        if (!document.getElementById("btnVdaTimersPill")) {
            var targetBar =
                document.querySelector(".header-right") ||
                document.querySelector(".header .btn-group") ||
                document.querySelector(".report-buttons") ||
                document.querySelector(".header");

            if (targetBar) {
                var pill = document.createElement("button");
                pill.type = "button";
                pill.id = "btnVdaTimersPill";
                pill.className = "btn-vda-timers";
                pill.title = "View Active Visitor Stay Countdowns, Reminders & Overstay Alarms";
                pill.innerHTML =
                    '<span>⏱️</span>' +
                    '<span id="vdaPillLabel">Visitor Timers</span>' +
                    '<span class="vda-pill-count" id="vdaPillCount">0</span>';
                pill.addEventListener("click", openTimersDrawer);
                targetBar.insertBefore(pill, targetBar.firstChild);
            }
        }

        // 3. Ensure Toast Container exists
        if (!document.getElementById("vdaToastStack")) {
            var ts = document.createElement("div");
            ts.id = "vdaToastStack";
            ts.className = "vda-toast-stack";
            document.body.appendChild(ts);
        }

        // 4. Ensure Active Visitor Timers Drawer exists
        if (!document.getElementById("vdaTimersDrawer")) {
            var overlay = document.createElement("div");
            overlay.id = "vdaTimersOverlay";
            overlay.className = "vda-drawer-overlay";
            overlay.addEventListener("click", closeTimersDrawer);

            var drawer = document.createElement("aside");
            drawer.id = "vdaTimersDrawer";
            drawer.className = "vda-drawer";
            drawer.innerHTML =
                '<div class="vda-drawer-header">' +
                    '<div class="vda-drawer-title">' +
                        '<span>⏱️ Visitor Duration &amp; Alarm Monitor</span>' +
                    '</div>' +
                    '<button type="button" class="btn-dismiss-alert" id="vdaCloseDrawerBtn" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="vda-drawer-toolbar">' +
                    '<div style="display:flex; gap:6px; flex-wrap:wrap;">' +
                        '<button type="button" class="vda-tool-btn" id="vdaDrawerMuteBtn">🔊 Sound On</button>' +
                        '<button type="button" class="vda-tool-btn" id="vdaTestReminderBtn" title="Preview the gentle reminder chime">🔔 Test Chime</button>' +
                        '<button type="button" class="vda-tool-btn" id="vdaTestAlarmBtn" title="Preview the overstay siren">🚨 Test Alarm</button>' +
                    '</div>' +
                    '<span id="vdaDrawerSummary" style="font-size:11px; font-weight:700; color:#475569;">0 inside</span>' +
                '</div>' +
                '<div class="vda-drawer-guide">' +
                    '<strong>Active Schedules per Duration:</strong><br>' +
                    '• <strong>15m Drop-off / 20m Delivery:</strong> Reminders at <strong>5m</strong> &amp; <strong>2m</strong> left + <strong>0:00 Alarm</strong><br>' +
                    '• <strong>8h Maintenance / 12h Birthday &amp; Resident:</strong> Reminders at <strong>1h, 30m, 15m, 5m</strong> + <strong>0:00 Alarm</strong>' +
                '</div>' +
                '<div class="vda-drawer-body" id="vdaDrawerBody">' +
                    '<div style="text-align:center; color:#64748b; padding:36px 16px; font-size:13px;">No active visitor timers right now.</div>' +
                '</div>';

            document.body.appendChild(overlay);
            document.body.appendChild(drawer);

            document.getElementById("vdaCloseDrawerBtn").addEventListener("click", closeTimersDrawer);
            document.getElementById("vdaDrawerMuteBtn").addEventListener("click", toggleMute);
            document.getElementById("vdaTestReminderBtn").addEventListener("click", function () {
                playReminderSound(true);
                showMilestoneToast("REMINDER", "🔔 Reminder Chime Test", "This tone plays at duration reminder milestones (1h, 30m, 15m, 5m left).");
            });
            document.getElementById("vdaTestAlarmBtn").addEventListener("click", function () {
                playOverstayAlarmSound(true);
                showMilestoneToast("OVERDUE", "🚨 Overstay Alarm Test", "This urgent alarm sounds when any visitor's duration reaches 00:00.");
            });
        }

        syncMuteButtonsUI();
    }

    function openTimersDrawer() {
        ensureBannerAndControls();
        var dr = document.getElementById("vdaTimersDrawer");
        var ov = document.getElementById("vdaTimersOverlay");
        if (dr) {
            dr.classList.add("open");
            dr.classList.add("notif-drawer"); // Keeps auto_refresh.js polite while open
        }
        if (ov) ov.classList.add("open");
        renderDrawerCards();
    }

    function closeTimersDrawer() {
        var dr = document.getElementById("vdaTimersDrawer");
        var ov = document.getElementById("vdaTimersOverlay");
        if (dr) dr.classList.remove("open");
        if (ov) ov.classList.remove("open");
    }

    function toggleTimersDrawer() {
        var dr = document.getElementById("vdaTimersDrawer");
        if (dr && dr.classList.contains("open")) {
            closeTimersDrawer();
        } else {
            openTimersDrawer();
        }
    }

    /* ---------------------------------------------------------------------
       MUTE & SNOOZE CONTROLS
    --------------------------------------------------------------------- */
    function syncMuteButtonsUI() {
        alarmMuted = localStorage.getItem(MUTE_STORAGE_KEY) === "true";

        var dBtn = document.getElementById("vdaDrawerMuteBtn");
        if (dBtn) {
            dBtn.innerHTML = alarmMuted ? "🔇 Muted" : "🔊 Sound On";
            dBtn.style.color = alarmMuted ? "#dc2626" : "#15803d";
            dBtn.style.borderColor = alarmMuted ? "#fca5a5" : "#86efac";
        }
        var bMute = document.getElementById("bannerMuteBtn");
        if (bMute) {
            bMute.innerHTML = alarmMuted ? "🔇 Unmute Alarm" : "🔊 Mute Alarm";
        }
        var legacyDrawerBtn = document.getElementById("btnToggleAlarmSound");
        if (legacyDrawerBtn) {
            legacyDrawerBtn.innerHTML = alarmMuted ? "🔇 Alarm Muted" : "🔊 Sound On";
            legacyDrawerBtn.style.color = alarmMuted ? "#ef4444" : "#16a34a";
        }
    }

    function toggleMute() {
        alarmMuted = !(localStorage.getItem(MUTE_STORAGE_KEY) === "true");
        localStorage.setItem(MUTE_STORAGE_KEY, alarmMuted ? "true" : "false");
        syncMuteButtonsUI();
        if (!alarmMuted) {
            playReminderSound(true);
        }
    }

    function snoozeVisitor(visitorId, minutes) {
        var mins = Math.max(1, parseInt(minutes || 5, 10));
        snoozedUntilMap[String(visitorId)] = Date.now() + (mins * 60 * 1000);
        saveJsonMap(SNOOZE_STORAGE_KEY, snoozedUntilMap, sessionStorage);
        renderDrawerCards();
        updateBannerAndHeaderUI();
    }

    function snoozeAllUrgent(minutes) {
        var mins = Math.max(1, parseInt(minutes || 5, 10));
        var until = Date.now() + (mins * 60 * 1000);
        activeTimers.forEach(function (v) {
            if (v._live && v._live.state !== "ACTIVE") {
                snoozedUntilMap[String(v.id)] = until;
            }
        });
        saveJsonMap(SNOOZE_STORAGE_KEY, snoozedUntilMap, sessionStorage);
        showMilestoneToast("REMINDER", "💤 Audio Snoozed (" + mins + " Mins)", "Alarm sound paused for " + mins + " minutes while visual timers remain active.");
        renderDrawerCards();
        updateBannerAndHeaderUI();
    }

    function currentBannerCompositeStage() {
        var parts = [];
        activeTimers.forEach(function (v) {
            if (v._live && v._live.state !== "ACTIVE") {
                parts.push(v.id + ":" + v.expected_out_ts + ":" + v._live.stage_key);
            }
        });
        return parts.join("|");
    }

    function dismissCurrentBannerStage() {
        dismissedBannerKey = currentBannerCompositeStage();
        try {
            sessionStorage.setItem(DISMISS_BANNER_KEY, dismissedBannerKey);
        } catch (e) {}
        var banner = document.getElementById("overstayAlertBanner");
        if (banner) {
            banner.style.display = "none";
        }
    }

    /* ---------------------------------------------------------------------
       TOAST & NOTIFICATION DISPATCH
    --------------------------------------------------------------------- */
    function showMilestoneToast(state, title, bodyHtml) {
        ensureBannerAndControls();
        var stack = document.getElementById("vdaToastStack");
        if (!stack) return;

        var toast = document.createElement("div");
        toast.className = "vda-toast" +
            (state === "OVERDUE" ? " toast-overdue" : (state === "FINAL_WARNING" ? " toast-final" : ""));

        var titleColor = state === "OVERDUE" ? "#be123c" : (state === "FINAL_WARNING" ? "#c2410c" : "#b45309");
        toast.innerHTML =
            '<div style="flex:1; min-width:0;">' +
                '<div style="font-weight:800; font-size:13px; color:' + titleColor + '; margin-bottom:3px;">' + escapeHtml(title) + '</div>' +
                '<div style="font-size:12px; color:#334155; line-height:1.4;">' + bodyHtml + '</div>' +
            '</div>' +
            '<button type="button" style="background:none;border:none;font-size:18px;color:#64748b;cursor:pointer;line-height:1;">&times;</button>';

        toast.querySelector("button").addEventListener("click", function () {
            toast.remove();
        });

        stack.appendChild(toast);
        setTimeout(function () {
            if (toast.parentNode) toast.remove();
        }, state === "OVERDUE" ? 10000 : 7500);

        // Optional Browser Desktop Notification if already granted
        if ("Notification" in window && Notification.permission === "granted" && document.hidden) {
            try {
                var plainBody = bodyHtml.replace(/<[^>]+>/g, "");
                new Notification(title, { body: plainBody });
            } catch (e) {}
        }
    }

    function recordStageTransition(v, liveEval) {
        var stageToken = v.id + "_" + v.expected_out_ts + "_" + liveEval.stage_key;
        if (notifiedStages[stageToken]) {
            return false;
        }
        notifiedStages[stageToken] = Date.now();
        saveJsonMap(STAGE_STORAGE_KEY, notifiedStages, sessionStorage);

        var snoozed = isVisitorSnoozed(v.id);
        var plate = v.plate_number || "Visitor";
        var purpose = v.purpose || v.duration_label || "Visitor";
        var dueAt = v.expected_time_out_formatted || "";

        if (liveEval.state === "OVERDUE") {
            if (!snoozed) {
                playOverstayAlarmSound(true);
            }
            var overTitle = liveEval.seconds_overdue >= 300
                ? ("🚨 Overstay Escalation (+" + liveEval.minutes_overdue + "m): " + plate)
                : ("🚨 Time Limit Expired: " + plate);
            var overSub = (v.vehicle_type || "Car") + " • " + purpose + " • Due " + dueAt + " (" + liveEval.minutes_overdue + "m overdue)";

            showMilestoneToast(
                "OVERDUE",
                overTitle,
                "Plate <strong>" + escapeHtml(plate) + "</strong> (" + escapeHtml(purpose) + ") has exceeded its stay duration!"
            );

            if (typeof window.addNotification === "function") {
                window.addNotification("OVERDUE", overTitle, overSub, v);
            }
        } else if (liveEval.state === "FINAL_WARNING") {
            if (!snoozed) {
                playFinalWarningSound(false);
            }
            var fwLabel = formatMilestoneChip(liveEval.active_milestone);
            var fwTitle = "⚡ Final " + fwLabel + " Warning: " + plate;
            var fwSub = (v.vehicle_type || "Car") + " • " + purpose + " • Expires at " + dueAt + " (" + liveEval.minutes_left + "m left)";

            showMilestoneToast(
                "FINAL_WARNING",
                fwTitle,
                "Plate <strong>" + escapeHtml(plate) + "</strong> (" + escapeHtml(purpose) + ") has <strong>" + escapeHtml(fwLabel) + "</strong> remaining (Due " + escapeHtml(dueAt) + ")."
            );

            if (typeof window.addNotification === "function") {
                window.addNotification("DUE_SOON", fwTitle, fwSub, v);
            }
        } else if (liveEval.state === "REMINDER") {
            if (!snoozed) {
                playReminderSound(false);
            }
            var remLabel = formatMilestoneChip(liveEval.active_milestone);
            var remTitle = "⏰ " + remLabel + " Reminder: " + plate;
            var remSub = (v.vehicle_type || "Car") + " • " + purpose + " • Due at " + dueAt + " (" + liveEval.minutes_left + "m left)";

            showMilestoneToast(
                "REMINDER",
                remTitle,
                "Plate <strong>" + escapeHtml(plate) + "</strong> (" + escapeHtml(purpose) + ") has <strong>" + escapeHtml(remLabel) + "</strong> left before due time (" + escapeHtml(dueAt) + ")."
            );

            if (typeof window.addNotification === "function") {
                window.addNotification("DUE_SOON", remTitle, remSub, v);
            }
        }
        return true;
    }

    /* ---------------------------------------------------------------------
       QUICK ACTIONS (+EXTEND DURATION & MARK EXITED)
    --------------------------------------------------------------------- */
    async function extendVisitorDuration(visitorId, plateNumber, extraMinutes) {
        try {
            var fd = new FormData();
            fd.append("action", "extend_duration");
            fd.append("role", detectPageRole());
            fd.append("visitor_id", String(visitorId || 0));
            fd.append("plate_number", String(plateNumber || ""));
            fd.append("extra_minutes", String(extraMinutes || 15));

            var res = await fetch("visitor_alarm_api.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            });
            var data = await res.json();
            if (data && data.success) {
                ingestSnapshot(data);
                showMilestoneToast(
                    "REMINDER",
                    "⏱️ Stay Extended (+" + extraMinutes + "m)",
                    "Updated timer for <strong>" + escapeHtml(plateNumber) + "</strong>."
                );
            }
        } catch (e) {}
    }

    async function markVisitorExited(visitorId, plateNumber) {
        if (!window.confirm("Mark visitor vehicle " + plateNumber + " as Exited (OUT) and clear its timer?")) {
            return;
        }
        try {
            var fd = new FormData();
            fd.append("action", "mark_exited");
            fd.append("role", detectPageRole());
            fd.append("visitor_id", String(visitorId || 0));
            fd.append("plate_number", String(plateNumber || ""));

            var res = await fetch("visitor_alarm_api.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            });
            var data = await res.json();
            if (data && data.success) {
                ingestSnapshot(data);
                showMilestoneToast(
                    "REMINDER",
                    "🔴 Marked Exited: " + plateNumber,
                    "Visitor timer closed and overstay alarm cleared."
                );
            }
        } catch (e) {}
    }

    /* ---------------------------------------------------------------------
       RENDERING: BANNER, HEADER PILL, INLINE BADGES & DRAWER
    --------------------------------------------------------------------- */
    function updateBannerAndHeaderUI() {
        ensureBannerAndControls();

        var overdueList = [];
        var finalList   = [];
        var remList     = [];

        for (var i = 0; i < activeTimers.length; i++) {
            var t = activeTimers[i];
            var st = t._live ? t._live.state : t.state;
            if (st === "OVERDUE") overdueList.push(t);
            else if (st === "FINAL_WARNING") finalList.push(t);
            else if (st === "REMINDER") remList.push(t);
        }

        // Update Header Pill
        var pill = document.getElementById("btnVdaTimersPill");
        var pillCount = document.getElementById("vdaPillCount");
        var pillLabel = document.getElementById("vdaPillLabel");
        if (pill && pillCount && pillLabel) {
            pillCount.textContent = String(activeTimers.length);
            pill.classList.remove("has-overdue", "has-warning");
            if (overdueList.length > 0) {
                pill.classList.add("has-overdue");
                pillLabel.textContent = overdueList.length + " Overdue!";
            } else if (finalList.length + remList.length > 0) {
                pill.classList.add("has-warning");
                pillLabel.textContent = (finalList.length + remList.length) + " Due Soon";
            } else if (activeTimers.length > 0) {
                var nearest = activeTimers[0];
                var nearClock = formatCountdownClock(nearest._live ? nearest._live.seconds_remaining : nearest.seconds_remaining);
                pillLabel.textContent = nearest.plate_number + " • " + nearClock;
            } else {
                pillLabel.textContent = "Visitor Timers";
            }
        }

        // Update Top Alert Banner
        var banner = document.getElementById("overstayAlertBanner");
        var textEl = document.getElementById("overstayAlertText");
        var iconEl = banner ? banner.querySelector(".overstay-alert-icon") : null;
        if (!banner || !textEl) return;

        // Clear any legacy inline color overrides from older code
        banner.style.background = "";
        banner.style.borderLeftColor = "";
        banner.style.borderColor = "";

        var compositeStage = currentBannerCompositeStage();
        if (compositeStage === "") {
            banner.style.display = "none";
            banner.classList.remove("vda-state-overdue", "vda-state-final", "vda-state-reminder");
            return;
        }

        // If user dismissed this exact stage combination, keep hidden until ANY stage advances
        if (dismissedBannerKey && dismissedBannerKey === compositeStage) {
            banner.style.display = "none";
            return;
        }

        banner.style.display = "flex";
        banner.classList.remove("vda-state-overdue", "vda-state-final", "vda-state-reminder");

        if (overdueList.length > 0) {
            banner.classList.add("vda-state-overdue");
            if (iconEl) iconEl.textContent = "🚨";
            var firstO = overdueList[0];
            var overClock = "+" + formatCountdownClock(firstO._live ? firstO._live.seconds_overdue : firstO.seconds_overdue);
            var extraO = overdueList.length > 1
                ? (' &bull; <strong>+' + (overdueList.length - 1) + ' more overdue</strong>')
                : "";
            textEl.innerHTML =
                '<strong>🚨 VISITOR OVERSTAY ALARM:</strong> Plate <strong>' + escapeHtml(firstO.plate_number) + '</strong> ' +
                '(' + escapeHtml(firstO.purpose) + ') exceeded stay limit! Due: ' + escapeHtml(firstO.expected_time_out_formatted) + ' ' +
                '<span class="vda-banner-clock">' + overClock + ' OVERDUE</span>' + extraO;
        } else if (finalList.length > 0) {
            banner.classList.add("vda-state-final");
            if (iconEl) iconEl.textContent = "⚡";
            var firstF = finalList[0];
            var fClock = formatCountdownClock(firstF._live ? firstF._live.seconds_remaining : firstF.seconds_remaining);
            var extraF = (finalList.length + remList.length) > 1
                ? (' &bull; <strong>+' + (finalList.length + remList.length - 1) + ' other due soon</strong>')
                : "";
            textEl.innerHTML =
                '<strong>⚡ FINAL WARNING (' + escapeHtml(firstF.duration_label) + '):</strong> Plate <strong>' + escapeHtml(firstF.plate_number) + '</strong> ' +
                '(' + escapeHtml(firstF.purpose) + ') time expires in ' +
                '<span class="vda-banner-clock">' + fClock + '</span> (Due ' + escapeHtml(firstF.expected_time_out_formatted) + ')' + extraF;
        } else if (remList.length > 0) {
            banner.classList.add("vda-state-reminder");
            if (iconEl) iconEl.textContent = "⏰";
            var firstR = remList[0];
            var rClock = formatCountdownClock(firstR._live ? firstR._live.seconds_remaining : firstR.seconds_remaining);
            var msTag = firstR._live && firstR._live.active_milestone ? formatMilestoneChip(firstR._live.active_milestone) : "";
            var extraR = remList.length > 1
                ? (' &bull; <strong>+' + (remList.length - 1) + ' more</strong>')
                : "";
            textEl.innerHTML =
                '<strong>⏰ DURATION REMINDER (' + escapeHtml(msTag ? msTag + ' Left' : firstR.duration_label) + '):</strong> Plate <strong>' + escapeHtml(firstR.plate_number) + '</strong> ' +
                '(' + escapeHtml(firstR.purpose) + ') has ' +
                '<span class="vda-banner-clock">' + rClock + '</span> remaining (Due ' + escapeHtml(firstR.expected_time_out_formatted) + ')' + extraR;
        }
    }

    function updateInlineTimerBadges() {
        var nodes = document.querySelectorAll("[data-vda-plate]");
        if (!nodes.length) return;

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            var pNorm = normalizePlate(el.getAttribute("data-vda-plate"));
            var dirAttr = String(el.getAttribute("data-vda-status") || "IN").toUpperCase();

            if (dirAttr === "OUT" || dirAttr === "OUTSIDE") {
                el.className = "vda-inline-timer";
                el.style.background = "#f1f5f9";
                el.style.color = "#64748b";
                el.style.border = "1px solid #e2e8f0";
                el.textContent = "✓ Completed";
                continue;
            }

            el.style.background = "";
            el.style.color = "";
            el.style.border = "";

            var timer = timersByPlateNorm[pNorm];
            if (timer && timer._live) {
                var lv = timer._live;
                if (lv.state === "OVERDUE") {
                    el.className = "vda-inline-timer vda-t-overdue";
                    el.textContent = "🚨 +" + formatCountdownClock(lv.seconds_overdue) + " OVERDUE";
                    el.title = timer.purpose + " • Due at " + timer.expected_time_out_formatted;
                } else if (lv.state === "FINAL_WARNING") {
                    el.className = "vda-inline-timer vda-t-final";
                    el.textContent = "⚡ " + formatCountdownClock(lv.seconds_remaining) + " left";
                    el.title = "Final Warning • Due at " + timer.expected_time_out_formatted;
                } else if (lv.state === "REMINDER") {
                    el.className = "vda-inline-timer vda-t-reminder";
                    el.textContent = "⏰ " + formatCountdownClock(lv.seconds_remaining) + " left";
                    el.title = "Reminder • Due at " + timer.expected_time_out_formatted;
                } else {
                    el.className = "vda-inline-timer vda-t-active";
                    el.textContent = "⏱ " + formatCountdownClock(lv.seconds_remaining);
                    el.title = timer.purpose + " • Due at " + timer.expected_time_out_formatted;
                }
            } else if (pendingByPlateNorm[pNorm]) {
                el.className = "vda-inline-timer vda-t-pending";
                el.textContent = "⏳ Set Duration";
                el.title = "Select visitor purpose & duration to start countdown";
            } else {
                // Hide empty badge on dashboard cards if vehicle is no longer active
                if (el.getAttribute("data-vda-compact") === "1") {
                    el.style.display = "none";
                    continue;
                }
                el.className = "vda-inline-timer vda-t-pending";
                el.textContent = "—";
            }
            el.style.display = "inline-flex";
        }
    }

    function renderDrawerCards() {
        var drawer = document.getElementById("vdaTimersDrawer");
        var summaryEl = document.getElementById("vdaDrawerSummary");
        var bodyEl = document.getElementById("vdaDrawerBody");
        if (!bodyEl) return;

        if (summaryEl) {
            summaryEl.textContent = activeTimers.length + " active" +
                (pendingVisitors.length > 0 ? (" • " + pendingVisitors.length + " pending") : "");
        }

        if (!drawer || !drawer.classList.contains("open")) {
            return;
        }

        if (activeTimers.length === 0 && pendingVisitors.length === 0) {
            bodyEl.innerHTML =
                '<div style="text-align:center; color:#64748b; padding:42px 16px;">' +
                    '<div style="font-size:32px; margin-bottom:8px;">⏱️</div>' +
                    '<div style="font-weight:700; color:#334155; margin-bottom:4px;">No Active Visitor Timers</div>' +
                    '<div style="font-size:12px;">When a visitor enters and selects a duration (15m, 20m, 8h, 12h, or Custom), their live countdown and reminder milestones appear here automatically.</div>' +
                '</div>';
            return;
        }

        var html = "";

        // Pending visitors awaiting duration selection
        pendingVisitors.forEach(function (pv) {
            html +=
                '<div class="vda-timer-card" style="border-left-color:#94a3b8; background:#f8fafc;">' +
                    '<div class="vda-card-top">' +
                        '<div>' +
                            '<span class="vda-plate-chip">' + escapeHtml(pv.plate_number) + '</span> ' +
                            '<span style="font-size:11px; color:#64748b; font-weight:700;">' + escapeHtml(pv.vehicle_type) + '</span>' +
                        '</div>' +
                        '<span class="vda-inline-timer vda-t-pending">⏳ Needs Duration</span>' +
                    '</div>' +
                    '<div style="font-size:12px; color:#475569;">Entered at <strong>' + escapeHtml(pv.time_in_formatted) + '</strong> — Select purpose &amp; stay duration to start reminders.</div>' +
                '</div>';
        });

        // Active visitor countdown cards
        var nowSec = nowServerSeconds();
        activeTimers.forEach(function (v) {
            var lv = v._live || evaluateLiveTimer(v.allowed_minutes, v.seconds_remaining);
            var cardCls = "vda-timer-card";
            if (lv.state === "OVERDUE") cardCls += " card-overdue";
            else if (lv.state === "FINAL_WARNING") cardCls += " card-final";
            else if (lv.state === "REMINDER") cardCls += " card-reminder";

            var clockText = lv.state === "OVERDUE"
                ? ("+" + formatCountdownClock(lv.seconds_overdue) + " OVER")
                : formatCountdownClock(lv.seconds_remaining);

            var totalSpan = Math.max(1, v.expected_out_ts - v.time_in_ts);
            var elapsedSpan = Math.max(0, nowSec - v.time_in_ts);
            var pct = Math.min(100, Math.max(0, (elapsedSpan / totalSpan) * 100)).toFixed(1);

            // Milestone chips
            var msHtml = "";
            var milestones = lv.milestones || [];
            for (var m = 0; m < milestones.length; m++) {
                var msMin = milestones[m];
                var chipCls = "vda-ms-chip";
                var prefix = "🔔 ";
                if (lv.state === "OVERDUE" || lv.seconds_remaining <= msMin * 60) {
                    if (lv.active_milestone === msMin && lv.state !== "OVERDUE") {
                        chipCls += " ms-active";
                    } else {
                        chipCls += " ms-passed";
                        prefix = "✓ ";
                    }
                }
                msHtml += '<span class="' + chipCls + '">' + prefix + formatMilestoneChip(msMin) + ' left</span>';
            }
            msHtml += '<span class="vda-ms-chip' + (lv.state === "OVERDUE" ? " ms-alarm" : "") + '">🚨 0:00 Alarm</span>';

            var snoozed = isVisitorSnoozed(v.id);

            html +=
                '<div class="' + cardCls + '" data-vda-card-id="' + v.id + '">' +
                    '<div class="vda-card-top">' +
                        '<div>' +
                            '<span class="vda-plate-chip">' + escapeHtml(v.plate_number) + '</span> ' +
                            '<span style="font-size:11px; color:#475569; font-weight:700; margin-left:4px;">' + escapeHtml(v.vehicle_type) + '</span>' +
                            '<div style="font-size:12px; font-weight:700; color:#1e293b; margin-top:5px;">' + escapeHtml(v.purpose) + '</div>' +
                            '<div style="font-size:11px; color:#64748b; margin-top:2px;">In: <strong>' + escapeHtml(v.time_in_formatted) + '</strong> &rarr; Due: <strong>' + escapeHtml(v.expected_time_out_formatted) + '</strong> (' + escapeHtml(v.duration_label) + ')</div>' +
                        '</div>' +
                        '<div style="text-align:right;">' +
                            '<div class="vda-clock-big" data-vda-card-clock="' + v.id + '">' + clockText + '</div>' +
                            (snoozed ? '<div style="font-size:10px; color:#64748b; margin-top:3px;">💤 Snoozed</div>' : '') +
                        '</div>' +
                    '</div>' +
                    '<div class="vda-progress-track"><div class="vda-progress-bar" data-vda-card-bar="' + v.id + '" style="width:' + pct + '%;"></div></div>' +
                    '<div class="vda-milestones-row">' + msHtml + '</div>' +
                    '<div class="vda-card-actions">' +
                        '<button type="button" class="vda-act-btn act-extend" data-act="ext15" data-id="' + v.id + '" data-plate="' + escapeHtml(v.plate_number) + '">+15m</button>' +
                        '<button type="button" class="vda-act-btn act-extend" data-act="ext30" data-id="' + v.id + '" data-plate="' + escapeHtml(v.plate_number) + '">+30m</button>' +
                        '<button type="button" class="vda-act-btn act-extend" data-act="ext60" data-id="' + v.id + '" data-plate="' + escapeHtml(v.plate_number) + '">+1h</button>' +
                        '<button type="button" class="vda-act-btn" data-act="snooze5" data-id="' + v.id + '">💤 5m</button>' +
                        '<button type="button" class="vda-act-btn act-out" data-act="markout" data-id="' + v.id + '" data-plate="' + escapeHtml(v.plate_number) + '">🔴 Mark OUT</button>' +
                    '</div>' +
                '</div>';
        });

        bodyEl.innerHTML = html;

        // Bind card action buttons
        bodyEl.querySelectorAll("button[data-act]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var act = btn.getAttribute("data-act");
                var id = parseInt(btn.getAttribute("data-id") || "0", 10);
                var plate = btn.getAttribute("data-plate") || "";
                if (act === "ext15") extendVisitorDuration(id, plate, 15);
                else if (act === "ext30") extendVisitorDuration(id, plate, 30);
                else if (act === "ext60") extendVisitorDuration(id, plate, 60);
                else if (act === "snooze5") snoozeVisitor(id, 5);
                else if (act === "markout") markVisitorExited(id, plate);
            });
        });
    }

    /* ---------------------------------------------------------------------
       1-SECOND LIVE TICKER & SNAPSHOT INGESTION
    --------------------------------------------------------------------- */
    function tick() {
        var nowSec = nowServerSeconds();
        var anyUnsnoozedOverdue = false;
        var stageChanged = false;

        for (var i = 0; i < activeTimers.length; i++) {
            var v = activeTimers[i];
            var secRem = v.expected_out_ts - nowSec;
            var prevStage = v._live ? v._live.stage_key : null;
            var lv = evaluateLiveTimer(v.allowed_minutes, secRem);
            v._live = lv;

            if (lv.state !== "ACTIVE") {
                if (recordStageTransition(v, lv)) {
                    stageChanged = true;
                }
            }
            if (prevStage && prevStage !== lv.stage_key) {
                stageChanged = true;
            }
            if (lv.state === "OVERDUE" && !isVisitorSnoozed(v.id)) {
                anyUnsnoozedOverdue = true;
            }

            // Fast in-place clock update if drawer is open without rebuilding buttons
            var clockEl = document.querySelector('[data-vda-card-clock="' + v.id + '"]');
            if (clockEl) {
                clockEl.textContent = lv.state === "OVERDUE"
                    ? ("+" + formatCountdownClock(lv.seconds_overdue) + " OVER")
                    : formatCountdownClock(lv.seconds_remaining);
            }
            var barEl = document.querySelector('[data-vda-card-bar="' + v.id + '"]');
            if (barEl) {
                var tot = Math.max(1, v.expected_out_ts - v.time_in_ts);
                var elp = Math.max(0, nowSec - v.time_in_ts);
                barEl.style.width = Math.min(100, Math.max(0, (elp / tot) * 100)).toFixed(1) + "%";
            }
        }

        // Repeat overstay siren periodically while any un-snoozed visitor remains overdue
        if (anyUnsnoozedOverdue) {
            playOverstayAlarmSound(false);
        }

        updateBannerAndHeaderUI();
        updateInlineTimerBadges();

        if (stageChanged) {
            renderDrawerCards();
        }
    }

    function ingestSnapshot(data, fromExternalFeed) {
        if (!data || !data.success) return;

        if (fromExternalFeed) {
            externalFeedActive = true;
        }

        if (typeof data.server_now_ts === "number" && data.server_now_ts > 0) {
            serverClockOffsetMs = (data.server_now_ts * 1000) - Date.now();
        }

        var prevCount = activeTimers.length + ":" + pendingVisitors.length;
        activeTimers = Array.isArray(data.active_timers) ? data.active_timers : [];
        pendingVisitors = Array.isArray(data.pending_info_visitors) ? data.pending_info_visitors : [];

        timersByPlateNorm = {};
        activeTimers.forEach(function (t) {
            var pNorm = t.plate_norm || normalizePlate(t.plate_number);
            timersByPlateNorm[pNorm] = t;
        });

        pendingByPlateNorm = {};
        pendingVisitors.forEach(function (pv) {
            pendingByPlateNorm[normalizePlate(pv.plate_number)] = pv;
        });

        tick();

        var newCount = activeTimers.length + ":" + pendingVisitors.length;
        if (prevCount !== newCount) {
            renderDrawerCards();
        }
    }

    async function pollStandaloneApi() {
        if (externalFeedActive || isFetchingApi || document.hidden) return;
        isFetchingApi = true;
        try {
            var role = encodeURIComponent(detectPageRole());
            var res = await fetch("visitor_alarm_api.php?action=status&role=" + role, {
                cache: "no-store",
                credentials: "same-origin"
            });
            if (res.ok) {
                var data = await res.json();
                ingestSnapshot(data, false);
            }
        } catch (e) {
            // Silently ignore transient network glitches
        } finally {
            isFetchingApi = false;
        }
    }

    function init() {
        injectStyles();
        ensureBannerAndControls();

        // Re-hydrate inline badges immediately whenever auto_refresh.js swaps [data-live] HTML
        document.addEventListener("live:updated", function () {
            ensureBannerAndControls();
            updateInlineTimerBadges();
        });

        // Smooth 1-second countdown ticker
        setInterval(tick, 1000);

        // Standalone background polling (automatically yields if guard_dashboard feeds snapshots)
        setTimeout(function () {
            if (!externalFeedActive) {
                pollStandaloneApi();
            }
        }, 400);
        pollTimerId = setInterval(pollStandaloneApi, POLL_INTERVAL_MS);

        document.addEventListener("visibilitychange", function () {
            if (!document.hidden && !externalFeedActive) {
                pollStandaloneApi();
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    window.VisitorDurationAlarm = {
        ingestSnapshot: ingestSnapshot,
        openTimersDrawer: openTimersDrawer,
        closeTimersDrawer: closeTimersDrawer,
        toggleTimersDrawer: toggleTimersDrawer,
        toggleMute: toggleMute,
        syncMuteButtonsUI: syncMuteButtonsUI,
        playReminderSound: playReminderSound,
        playFinalWarningSound: playFinalWarningSound,
        playOverstayAlarmSound: playOverstayAlarmSound,
        snoozeVisitor: snoozeVisitor,
        snoozeAllUrgent: snoozeAllUrgent,
        extendVisitorDuration: extendVisitorDuration,
        markVisitorExited: markVisitorExited,
        getDurationScheduleInfo: getDurationScheduleInfo,
        updateInlineTimerBadges: updateInlineTimerBadges
    };
})();

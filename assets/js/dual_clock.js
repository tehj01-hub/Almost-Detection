/* =========================================================
   DUAL CLOCK (Internet Time + System Time)
   ---------------------------------------------------------
   Used by the Administrator and Security Guard dashboards.

   - Internet clock: synced from internet_time.php (NTP servers,
     HTTPS Date fallback), corrected for network round-trip, then
     ticks locally. Re-syncs every 5 minutes.
   - System clock: this unit's own clock (Date()), exactly like
     the old single clock.
   - Each card shows how far the unit's clock drifts from internet
     time, so a wrong PC clock is easy to spot.

   Markup (ids):
     #internetClock, #internetClockStatus  -> Internet card
     #liveClock,     #systemClockDrift     -> System card
========================================================= */

(function () {

    "use strict";

    var SYNC_URL = "internet_time.php";
    var RESYNC_MS = 5 * 60 * 1000;      // normal re-sync
    var RETRY_MS = 30 * 1000;           // retry after a failed sync
    var TIMEZONE = "Asia/Manila";       // system timezone (app/session_helper.php)

    var internetOffsetMs = null;        // internet time - this device's clock
    var lastSyncOk = false;
    var lastStale = false;
    var lastSource = "";
    var syncTimer = null;

    var fmtInternet;
    try {
        fmtInternet = new Intl.DateTimeFormat("en-US", {
            timeZone: TIMEZONE, hour: "numeric", minute: "2-digit", second: "2-digit", hour12: true
        });
    } catch (e) {
        fmtInternet = null;
    }

    function pad(n) { return n < 10 ? "0" + n : String(n); }

    /* Same "h:mm:ss AM" format the dashboards always used. */
    function formatLocal(d) {
        var h = d.getHours();
        var period = h >= 12 ? "PM" : "AM";
        h = h % 12; h = h ? h : 12;
        return h + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds()) + " " + period;
    }

    function formatInternet(ms) {
        var d = new Date(ms);
        if (fmtInternet) {
            return fmtInternet.format(d).replace(/ /g, " ");
        }
        return formatLocal(d);
    }

    function setBadge(el, text, kind, title) {
        if (!el) return;
        el.textContent = text;
        el.className = "clock-badge" + (kind ? " clock-badge-" + kind : "");
        if (title) el.title = title;
    }

    function describeDrift(driftMs) {
        var abs = Math.abs(driftMs);
        if (abs < 1000) return { text: "In sync", kind: "ok" };
        var secs = Math.round(abs / 1000);
        var amount = secs < 60 ? secs + "s"
            : secs < 3600 ? Math.round(secs / 60) + "m"
            : (secs / 3600).toFixed(1) + "h";
        return {
            text: amount + (driftMs > 0 ? " ahead" : " behind"),
            kind: secs >= 60 ? "bad" : "warn"
        };
    }

    function tick() {
        var nowMs = Date.now();

        var sysEl = document.getElementById("liveClock");
        if (sysEl) sysEl.textContent = formatLocal(new Date(nowMs));

        var netEl = document.getElementById("internetClock");
        var netStatus = document.getElementById("internetClockStatus");
        var driftEl = document.getElementById("systemClockDrift");

        if (internetOffsetMs === null) {
            if (netEl) netEl.textContent = "--:--:--";
            setBadge(driftEl, "", "");
            return;
        }

        if (netEl) netEl.textContent = formatInternet(nowMs + internetOffsetMs);

        if (lastSyncOk && !lastStale) {
            setBadge(netStatus, "Synced", "ok", "Source: " + lastSource);
        } else {
            setBadge(netStatus, "Offline · last sync", "warn",
                "Internet unreachable. Showing time from the last successful sync (" + lastSource + ").");
        }

        // Positive drift = this unit's clock is ahead of internet time.
        var d = describeDrift(-internetOffsetMs);
        setBadge(driftEl, d.text, d.kind,
            "This unit's clock compared with internet time (" + (-internetOffsetMs / 1000).toFixed(2) + " s).");
    }

    function scheduleSync(ms) {
        if (syncTimer) clearTimeout(syncTimer);
        syncTimer = setTimeout(sync, ms);
    }

    function sync() {
        var netStatus = document.getElementById("internetClockStatus");
        if (internetOffsetMs === null) setBadge(netStatus, "Syncing…", "");

        var sentAt = Date.now();
        fetch(SYNC_URL + "?t=" + sentAt, { cache: "no-store", credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var receivedAt = Date.now();
                if (!data || !data.success || typeof data.epoch_ms !== "number") {
                    throw new Error((data && data.message) || "sync failed");
                }
                // Server stamped the time roughly halfway through the round trip.
                internetOffsetMs = data.epoch_ms - (sentAt + receivedAt) / 2;
                lastSyncOk = true;
                lastStale = !!data.stale;
                lastSource = data.source || "internet";
                tick();
                scheduleSync(lastStale ? RETRY_MS : RESYNC_MS);
            })
            .catch(function () {
                lastSyncOk = false;
                if (internetOffsetMs === null) {
                    setBadge(document.getElementById("internetClockStatus"), "Offline", "bad",
                        "Could not reach the internet time servers.");
                }
                tick();
                scheduleSync(RETRY_MS);
            });
    }

    function injectStyles() {
        if (document.getElementById("dualClockStyles")) return;
        var css = [
            ".dual-clock{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;}",
            ".clock-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:6px 12px 7px;",
            "  min-width:150px;box-shadow:0 1px 2px rgba(15,23,42,.06);}",
            ".clock-label{display:flex;align-items:center;justify-content:space-between;gap:8px;",
            "  font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}",
            ".dual-clock .clock{font-size:22px !important;line-height:1.25;font-variant-numeric:tabular-nums;white-space:nowrap;}",
            ".clock-badge{font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:9999px;",
            "  background:#f1f5f9;color:#475569;text-transform:none;letter-spacing:0;}",
            ".clock-badge:empty{display:none;}",
            ".clock-badge-ok{background:#dcfce7;color:#15803d;}",
            ".clock-badge-warn{background:#fef3c7;color:#b45309;}",
            ".clock-badge-bad{background:#fee2e2;color:#b91c1c;}",
            "@media (max-width:600px){.clock-card{min-width:0;flex:1 1 140px;}.dual-clock .clock{font-size:18px !important;}}"
        ].join("");
        var style = document.createElement("style");
        style.id = "dualClockStyles";
        style.textContent = css;
        document.head.appendChild(style);
    }

    function start() {
        injectStyles();
        tick();
        setInterval(tick, 1000);
        sync();
        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) sync();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }

})();

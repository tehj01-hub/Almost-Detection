/* =========================================================
   LOGOUT CONFIRMATION
   Every link to logout.php asks "Are you sure you want to log
   out?" before signing out. Cancel (focused by default), Esc or
   a click outside the box keeps the user signed in.
========================================================= */
(function () {
    "use strict";

    var OVERLAY_ID = "secsysLogoutConfirm";
    var lastFocus = null;
    var pendingHref = null;

    function injectStyles() {
        if (document.getElementById("secsysLogoutConfirmStyles")) return;
        var style = document.createElement("style");
        style.id = "secsysLogoutConfirmStyles";
        style.textContent =
            "#" + OVERLAY_ID + "{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;" +
            "padding:16px;background:rgba(15,23,42,.5);opacity:0;transition:opacity .18s ease;font-family:inherit;}" +
            "#" + OVERLAY_ID + ".open{opacity:1;}" +
            "#" + OVERLAY_ID + " .slc-box{width:360px;max-width:100%;background:#fff;border-radius:16px;padding:24px 22px 18px;" +
            "box-shadow:0 20px 50px rgba(15,23,42,.3);text-align:center;transform:translateY(10px) scale(.97);transition:transform .18s ease;}" +
            "#" + OVERLAY_ID + ".open .slc-box{transform:none;}" +
            "#" + OVERLAY_ID + " .slc-icon{width:52px;height:52px;margin:0 auto 12px;border-radius:50%;background:#fee2e2;" +
            "display:flex;align-items:center;justify-content:center;font-size:26px;}" +
            "#" + OVERLAY_ID + " .slc-title{font-size:18px;font-weight:700;color:#0f172a;margin-bottom:6px;}" +
            "#" + OVERLAY_ID + " .slc-text{font-size:13.5px;color:#475569;line-height:1.5;margin-bottom:18px;}" +
            "#" + OVERLAY_ID + " .slc-actions{display:flex;gap:10px;}" +
            "#" + OVERLAY_ID + " .slc-actions button{flex:1;height:40px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;" +
            "transition:background .15s ease,transform .1s ease;}" +
            "#" + OVERLAY_ID + " .slc-actions button:active{transform:scale(.97);}" +
            "#" + OVERLAY_ID + " .slc-cancel{background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;}" +
            "#" + OVERLAY_ID + " .slc-cancel:hover{background:#e2e8f0;}" +
            "#" + OVERLAY_ID + " .slc-confirm{background:#dc2626;color:#fff;border:1px solid #dc2626;}" +
            "#" + OVERLAY_ID + " .slc-confirm:hover{background:#b91c1c;}" +
            "#" + OVERLAY_ID + " button:focus-visible{outline:2px solid #2563eb;outline-offset:2px;}" +
            "@media (prefers-reduced-motion: reduce){#" + OVERLAY_ID + ",#" + OVERLAY_ID + " .slc-box{transition:none;}}";
        document.head.appendChild(style);
    }

    function close() {
        var overlay = document.getElementById(OVERLAY_ID);
        if (!overlay) return;
        overlay.classList.remove("open");
        document.removeEventListener("keydown", onKey, true);
        setTimeout(function () { overlay.remove(); }, 180);
        pendingHref = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function confirmLogout() {
        var href = pendingHref;
        var btn = document.querySelector("#" + OVERLAY_ID + " .slc-confirm");
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Logging out...";
        }
        if (href) window.location.href = href;
    }

    function onKey(e) {
        if (e.key === "Escape") {
            e.preventDefault();
            close();
        } else if (e.key === "Tab") {
            // Keep keyboard focus inside the box
            var buttons = document.querySelectorAll("#" + OVERLAY_ID + " button");
            if (!buttons.length) return;
            var first = buttons[0], last = buttons[buttons.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    function open(href) {
        if (document.getElementById(OVERLAY_ID)) return;
        injectStyles();
        lastFocus = document.activeElement;
        pendingHref = href;

        var isGuard = /role=Security(%20|\+|\s)Guard/i.test(href);
        var overlay = document.createElement("div");
        overlay.id = OVERLAY_ID;
        overlay.setAttribute("role", "alertdialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.setAttribute("aria-labelledby", "slcTitle");
        overlay.setAttribute("aria-describedby", "slcText");
        overlay.innerHTML =
            '<div class="slc-box">' +
                '<div class="slc-icon" aria-hidden="true">&#128682;</div>' +
                '<div class="slc-title" id="slcTitle">Log out?</div>' +
                '<div class="slc-text" id="slcText">' +
                    (isGuard
                        ? "Are you sure you want to log out? Your duty session will end and you will need to sign in again."
                        : "Are you sure you want to log out? You will need to sign in again to use the system.") +
                '</div>' +
                '<div class="slc-actions">' +
                    '<button type="button" class="slc-cancel">Cancel</button>' +
                    '<button type="button" class="slc-confirm">Log out</button>' +
                '</div>' +
            '</div>';

        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) close();
        });
        overlay.querySelector(".slc-cancel").addEventListener("click", close);
        overlay.querySelector(".slc-confirm").addEventListener("click", confirmLogout);

        document.body.appendChild(overlay);
        document.addEventListener("keydown", onKey, true);
        requestAnimationFrame(function () { overlay.classList.add("open"); });
        overlay.querySelector(".slc-cancel").focus();
    }

    // Any link to logout.php (sidebars, menus) asks first
    document.addEventListener("click", function (e) {
        var link = e.target.closest && e.target.closest('a[href*="logout.php"]');
        if (!link || e.defaultPrevented) return;
        e.preventDefault(); // also Ctrl/Shift-click: a new tab would log out too
        open(link.getAttribute("href"));
    }, true);

    window.SecsysLogoutConfirm = { open: open, close: close };
})();

/* =========================================================
   KEEP YOUR PLACE AFTER A RELOAD
   ---------------------------------------------------------
   Filters, searches, page numbers and Save buttons on some
   pages reload the page, which would normally start again at
   the top. Just before such a reload, remember where you were;
   after it, scroll back to the same spot.

   When a filter form (with an id) outside a popup triggers the
   reload, that form is put back at the same height on screen,
   so the page stays put even if the content above it changed.

   Usage (before </body>):
     <script src="assets/js/keep_scroll.js"></script>
========================================================= */

(function () {

    "use strict";

    var KEY = "secsys_keep_scroll:" + location.pathname;
    var page = location.pathname.split("/").pop() || "";

    function remember(anchorEl) {
        try {
            var data = { y: window.scrollY, t: Date.now() };
            if (anchorEl && anchorEl.id
                && !anchorEl.closest("[class*='modal'], [class*='overlay'], dialog")) {
                data.anchor = anchorEl.id;
                data.offset = anchorEl.getBoundingClientRect().top;
            }
            sessionStorage.setItem(KEY, JSON.stringify(data));
        } catch (e) {}
    }

    function targetsThisPage(url) {
        try {
            return new URL(url, location.href).pathname.split("/").pop() === page;
        } catch (e) {
            return false;
        }
    }

    function isPageForm(form) {
        return !!form && form.tagName === "FORM" && targetsThisPage(form.getAttribute("action") || location.href);
    }

    /* Runs before inline handlers such as onchange="this.form.submit()". */
    document.addEventListener("change", function (e) {
        var form = e.target && e.target.form;
        if (isPageForm(form)) {
            remember(form);
        }
    }, true);

    document.addEventListener("submit", function (e) {
        if (isPageForm(e.target)) {
            remember(e.target);
        }
    }, true);

    document.addEventListener("click", function (e) {
        if (!e.target.closest) {
            return;
        }
        /* Buttons that submit their form from script (e.g. Daily / Monthly) */
        var btn = e.target.closest("[data-keep-scroll]");
        if (btn) {
            remember(document.getElementById(btn.getAttribute("data-keep-scroll")) || null);
            return;
        }
        var link = e.target.closest("a[href]");
        if (link && !link.target && targetsThisPage(link.getAttribute("href"))
            && !/[?&]download=/.test(link.getAttribute("href"))
            && link.getAttribute("href").charAt(0) !== "#") {
            var sectionAnchor = link.closest(".section-card, [id^='liveReport'], .table-card");
            remember(sectionAnchor || null);
        }
    }, true);

    function restore() {
        var data = null;
        try {
            data = JSON.parse(sessionStorage.getItem(KEY) || "null");
            sessionStorage.removeItem(KEY);
        } catch (e) {}
        if (!data || Date.now() - data.t > 15000) {
            return;
        }
        var anchor = data.anchor ? document.getElementById(data.anchor) : null;
        var userScrolled = false;
        window.addEventListener("wheel", function () { userScrolled = true; }, { once: true, passive: true });
        window.addEventListener("touchmove", function () { userScrolled = true; }, { once: true, passive: true });

        function go() {
            if (userScrolled) {
                return;
            }
            var y = anchor
                ? window.scrollY + anchor.getBoundingClientRect().top - data.offset
                : data.y;
            window.scrollTo(0, Math.max(0, y));
        }

        go();
        /* Again once fonts / images have settled the layout */
        window.addEventListener("load", go, { once: true });
        setTimeout(go, 300);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", restore);
    } else {
        restore();
    }

})();

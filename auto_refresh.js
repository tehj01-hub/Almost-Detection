/* =========================================================
   LIVE AUTO-REFRESH
   ---------------------------------------------------------
   Silently re-fetches the current page in the background
   and swaps ONLY the elements marked with `data-live`
   (each must have a unique id). The rest of the page
   (camera streams, open modals, scroll position, search
   inputs) stays untouched, so no full page reload happens.

   Usage (before </body>):
     <script>window.AUTO_REFRESH_SECONDS = 5;</script>
     <script src="auto_refresh.js"></script>

   The refresh is skipped while the user is busy:
     - typing in an input / textarea / select
     - a modal or dropdown is open
     - a checkbox inside a live region is checked
     - the browser tab is hidden
========================================================= */

(function () {

    "use strict";

    var INTERVAL_MS = (window.AUTO_REFRESH_SECONDS || 5) * 1000;

    /* Elements that mean "the user is in the middle of something". */
    var BUSY_SELECTORS = [
        ".modal.show",
        ".modal.active",
        ".modal-overlay.open",
        ".tapo-modal-overlay.active",
        ".notif-drawer.open",
        ".visitor-modal-overlay.active",
        ".sort-menu.show",
        ".sort-menu.open",
        ".sort-open",
        ".dropdown-menu.show"
    ].join(",");

    var isFetching = false;
    var indicator = null;


    /* ---------------------------------------------------------
       UI: small "Live" indicator in the bottom-left corner
    --------------------------------------------------------- */

    function createIndicator() {

        indicator = document.createElement("div");
        indicator.id = "liveRefreshIndicator";
        indicator.style.cssText = [
            "position:fixed",
            "left:12px",
            "bottom:12px",
            "z-index:9998",
            "background:rgba(17,24,39,0.82)",
            "color:#fff",
            "font:600 11px/1 Arial, sans-serif",
            "padding:6px 10px",
            "border-radius:12px",
            "display:flex",
            "align-items:center",
            "gap:6px",
            "pointer-events:none",
            "box-shadow:0 2px 6px rgba(0,0,0,0.2)"
        ].join(";");

        indicator.innerHTML =
            '<span id="liveRefreshDot" style="width:7px;height:7px;' +
            'border-radius:50%;background:#22c55e;display:inline-block;"></span>' +
            '<span id="liveRefreshText">Live</span>';

        document.body.appendChild(indicator);
    }

    function setIndicator(text, color) {

        if (!indicator) {
            return;
        }

        document.getElementById("liveRefreshText").textContent = text;
        document.getElementById("liveRefreshDot").style.background = color;
    }

    function timeNow() {

        return new Date().toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit"
        });
    }


    /* ---------------------------------------------------------
       BUSY CHECK
    --------------------------------------------------------- */

    function userIsBusy() {

        if (document.hidden) {
            return true;
        }

        var active = document.activeElement;

        if (
            active &&
            (
                active.tagName === "TEXTAREA" ||
                active.tagName === "SELECT" ||
                (
                    active.tagName === "INPUT" &&
                    active.type !== "checkbox" &&
                    active.type !== "radio" &&
                    active.type !== "button" &&
                    active.type !== "submit"
                )
            )
        ) {
            return true;
        }

        if (document.querySelector(BUSY_SELECTORS)) {
            return true;
        }

        /* Don't wipe out the guard's row selections. */
        if (document.querySelector("[data-live] input[type=checkbox]:checked")) {
            return true;
        }

        return false;
    }


    /* ---------------------------------------------------------
       CHART UPDATE (dashboards)
       Expects <script type="application/json" id="liveChartData">
       containing {"labels": [...], "data": [[...], [...], ...]}
    --------------------------------------------------------- */

    function updateChart() {

        var dataEl = document.getElementById("liveChartData");

        if (!dataEl || typeof Chart === "undefined" || !Chart.getChart) {
            return;
        }

        var chart = Chart.getChart("vehicleChart");

        if (!chart) {
            return;
        }

        try {

            var payload = JSON.parse(dataEl.textContent);

            chart.data.labels = payload.labels;

            payload.data.forEach(function (series, i) {
                if (chart.data.datasets[i]) {
                    chart.data.datasets[i].data = series;
                }
            });

            chart.update("none");

        } catch (e) {
            /* Ignore malformed chart data. */
        }
    }


    /* ---------------------------------------------------------
       REFRESH
    --------------------------------------------------------- */

    function refresh() {

        if (isFetching || userIsBusy()) {
            return;
        }

        var regions = document.querySelectorAll("[data-live][id]");

        if (regions.length === 0) {
            return;
        }

        isFetching = true;

        fetch(window.location.href, {
            method: "GET",
            cache: "no-store",
            credentials: "same-origin",
            headers: { "X-Auto-Refresh": "1" }
        })
        .then(function (response) {

            /* Session expired -> server redirected to login. */
            var currentPath = window.location.pathname;
            var finalPath = new URL(response.url).pathname;

            if (response.redirected && finalPath !== currentPath) {
                window.location.href = response.url;
                return null;
            }

            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }

            return response.text();
        })
        .then(function (html) {

            if (html === null) {
                return;
            }

            /* Re-check: user may have started interacting while we waited. */
            if (userIsBusy()) {
                return;
            }

            var doc = new DOMParser().parseFromString(html, "text/html");
            var changed = false;

            regions.forEach(function (region) {

                var fresh = doc.getElementById(region.id);

                if (fresh && fresh.innerHTML !== region.innerHTML) {
                    region.innerHTML = fresh.innerHTML;
                    changed = true;
                }
            });

            if (changed) {
                updateChart();
                document.dispatchEvent(new CustomEvent("live:updated"));
            }

            setIndicator("Live \u00b7 " + timeNow(), "#22c55e");
        })
        .catch(function () {
            setIndicator("Reconnecting\u2026", "#f59e0b");
        })
        .then(function () {
            isFetching = false;
        });
    }


    /* ---------------------------------------------------------
       START
    --------------------------------------------------------- */

    function start() {

        createIndicator();
        setIndicator("Live \u00b7 " + timeNow(), "#22c55e");

        setInterval(refresh, INTERVAL_MS);

        /* Refresh immediately when the guard returns to the tab. */
        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) {
                refresh();
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }

})();


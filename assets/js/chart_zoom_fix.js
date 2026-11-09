/* =========================================================
   CHART.JS POINTER FIX FOR THE 125% UI SCALE
   ---------------------------------------------------------
   assets/css/ui_scale.css enlarges the dashboards with CSS
   `zoom`. Chart.js reads mouse positions from `offsetX/offsetY`,
   which Chrome reports in a different unit than the canvas box
   under zoom, so tooltips appeared at the wrong hour.

   This global plugin recomputes the pointer position from
   clientX/clientY and the canvas' on-screen rectangle (always in
   the same coordinate space), then scales it to chart pixels.
   It is a no-op when the page is not zoomed.

   Load right after chart.js and before any `new Chart(...)`.
========================================================= */

(function () {

    "use strict";

    if (typeof Chart === "undefined" || !Chart.register) {
        return;
    }

    Chart.register({
        id: "zoomSafePointer",

        beforeEvent: function (chart, args) {
            var e = args.event;
            var native = e && e.native;

            // Mouse / pointer events only (touch keeps Chart.js' own handling).
            if (!native || typeof native.clientX !== "number") {
                return;
            }

            var rect = chart.canvas.getBoundingClientRect();
            if (!rect.width || !rect.height) {
                return;
            }

            e.x = (native.clientX - rect.left) * (chart.width / rect.width);
            e.y = (native.clientY - rect.top) * (chart.height / rect.height);

            var area = chart.chartArea;
            if (area) {
                args.inChartArea =
                    e.x >= area.left && e.x <= area.right &&
                    e.y >= area.top && e.y <= area.bottom;
            }
        }
    });

})();

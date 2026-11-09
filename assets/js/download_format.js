/* =========================================================
   DOWNLOAD FORMAT CHOOSER (PDF or CSV)
   ---------------------------------------------------------
   Every "Download" button opens a small menu to pick the
   file type. The server returns the same report as a PDF
   (default) or as a CSV when the URL has &format=csv.

   Usage:
     - Links:   <a href="page.php?download=..." data-download-choice>...</a>
     - Scripts: SecsysDownload.choose(url, buttonElement)
========================================================= */
(function () {
    "use strict";

    let menu = null;

    function injectStyles() {
        if (document.getElementById("secsysDownloadMenuStyles")) return;
        const style = document.createElement("style");
        style.id = "secsysDownloadMenuStyles";
        style.textContent =
            ".secsys-download-menu{position:absolute;z-index:100000;min-width:190px;background:#fff;" +
            "border:1px solid #d6dde8;border-radius:10px;box-shadow:0 10px 28px rgba(15,23,42,.18);" +
            "padding:6px;font-family:inherit;}" +
            ".secsys-download-menu .sdm-title{font-size:11px;font-weight:700;color:#64748b;" +
            "text-transform:uppercase;letter-spacing:.4px;padding:4px 8px 6px;}" +
            ".secsys-download-menu button{display:flex;align-items:center;gap:8px;width:100%;" +
            "border:none;background:transparent;padding:8px 10px;border-radius:7px;cursor:pointer;" +
            "font-size:13px;font-weight:600;color:#1e293b;text-align:left;}" +
            ".secsys-download-menu button:hover,.secsys-download-menu button:focus{background:#eef4ff;outline:none;}" +
            ".secsys-download-menu .sdm-note{font-size:11px;font-weight:500;color:#64748b;margin-left:auto;}";
        document.head.appendChild(style);
    }

    function close() {
        if (menu) {
            menu.remove();
            menu = null;
            document.removeEventListener("mousedown", onOutside, true);
            document.removeEventListener("keydown", onKey, true);
        }
    }

    function onOutside(e) {
        if (menu && !menu.contains(e.target)) close();
    }

    function onKey(e) {
        if (e.key === "Escape") close();
    }

    function withFormat(url, format) {
        const clean = String(url).replace(/([?&])format=[^&]*&?/g, "$1").replace(/[?&]$/, "");
        return clean + (clean.indexOf("?") === -1 ? "?" : "&") + "format=" + format;
    }

    function choose(url, anchorEl) {
        close();
        injectStyles();

        menu = document.createElement("div");
        menu.className = "secsys-download-menu";
        menu.setAttribute("role", "menu");
        menu.innerHTML =
            '<div class="sdm-title">Download as</div>' +
            '<button type="button" role="menuitem" data-format="pdf">📄 PDF <span class="sdm-note">for printing</span></button>' +
            '<button type="button" role="menuitem" data-format="csv">📊 CSV <span class="sdm-note">for Excel</span></button>';

        menu.addEventListener("click", function (e) {
            const btn = e.target.closest("button[data-format]");
            if (!btn) return;
            const target = withFormat(url, btn.getAttribute("data-format"));
            close();
            window.location.href = target;
        });

        document.body.appendChild(menu);

        // Position under the button, kept inside the window. The pages use
        // CSS zoom on <html> (assets/css/ui_scale.css), so screen
        // coordinates are divided by the zoom before positioning.
        const zoom = parseFloat(getComputedStyle(document.documentElement).zoom) || 1;
        const rect = (anchorEl && anchorEl.getBoundingClientRect)
            ? anchorEl.getBoundingClientRect()
            : { left: window.innerWidth / 2 - 95, bottom: window.innerHeight / 3 };
        const viewWidth = window.innerWidth / zoom;
        const menuWidth = menu.offsetWidth;
        let left = rect.left / zoom;
        if (left + menuWidth > viewWidth - 8) {
            left = viewWidth - menuWidth - 8;
        }
        menu.style.position = "fixed";
        menu.style.left = Math.max(8, left) + "px";
        menu.style.top = (rect.bottom / zoom + 6) + "px";

        const first = menu.querySelector("button");
        if (first) first.focus();

        setTimeout(function () {
            document.addEventListener("mousedown", onOutside, true);
            document.addEventListener("keydown", onKey, true);
            window.addEventListener("scroll", close, { once: true, capture: true });
        }, 0);
    }

    // Links marked with data-download-choice open the menu instead of downloading a PDF directly
    document.addEventListener("click", function (e) {
        const link = e.target.closest("[data-download-choice]");
        if (!link) return;
        const url = link.getAttribute("data-download-url") || link.getAttribute("href");
        if (!url) return;
        e.preventDefault();
        e.stopPropagation();
        choose(url, link);
    });

    window.SecsysDownload = { choose: choose, close: close };
})();

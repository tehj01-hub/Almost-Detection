/* =========================================================
   ARCHIVE SEARCH (shared)
   ---------------------------------------------------------
   Wires every <input class="archive-search-input" data-reload="fnName">
   inside an archive window:
     - filters as you type (short pause), Enter = search now,
       the (x) clear button of type=search also reloads
     - calls window[fnName](1) to reload page 1 of the archive
   Works after the archive window's HTML is swapped (event
   delegation), and keeps the cursor in the box:
     ArchiveSearch.capture()  -> call right before swapping HTML
     ArchiveSearch.restore()  -> call right after swapping HTML
   Used by registered.php, visitor.php, registered_logs.php,
   visitor_logs.php (logs.php has its own built-in version).
========================================================= */

(function () {

    "use strict";

    var DEBOUNCE_MS = 350;
    var timer = null;
    var saved = null;

    function isSearchBox(el) {
        return !!(el && el.classList && el.classList.contains("archive-search-input"));
    }

    function capture() {
        var active = document.activeElement;
        if (isSearchBox(active)) {
            saved = {
                id: active.id,
                value: active.value,
                pos: active.selectionStart
            };
        } else {
            saved = null;
        }
    }

    function restore() {
        if (!saved) {
            return;
        }
        var el = document.getElementById(saved.id);
        if (el) {
            // keep anything typed while the results were loading
            if (el.value !== saved.value) {
                el.value = saved.value;
            }
            el.focus();
            var pos = saved.pos === null ? el.value.length : Math.min(saved.pos, el.value.length);
            try { el.setSelectionRange(pos, pos); } catch (e) { /* type=search may not support it */ }
        }
        saved = null;
    }

    function run(el) {
        var fn = window[el.getAttribute("data-reload")];
        if (typeof fn === "function") {
            fn(1);
        }
    }

    document.addEventListener("input", function (e) {
        if (!isSearchBox(e.target)) return;
        var el = e.target;
        clearTimeout(timer);
        timer = setTimeout(function () { run(el); }, DEBOUNCE_MS);
    });

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Enter" || !isSearchBox(e.target)) return;
        e.preventDefault();               // never submit the surrounding form
        clearTimeout(timer);
        run(e.target);
    });

    // "search" fires when the (x) clear button is used; it does not bubble
    document.addEventListener("search", function (e) {
        if (!isSearchBox(e.target)) return;
        clearTimeout(timer);
        run(e.target);
    }, true);

    window.ArchiveSearch = {
        capture: capture,
        restore: restore,
        value: function (id) {
            var el = document.getElementById(id || "archiveSearch");
            return el ? el.value.trim() : "";
        }
    };

})();

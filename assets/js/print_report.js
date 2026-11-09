/* =========================================================
   PRINT REPORT LAYOUT (browser side)
   ---------------------------------------------------------
   Builds a printable document in the same format as the Visitor
   Vehicle Report (visitor.php -> Download PDF) and prints it
   through a hidden frame (no pop-up window).
   Styles come from assets/css/print_report.css; header / footer
   text matches app/print_report.php.

   SecsysPrint.table(columns, rows, emptyText, sectionTitle)
     columns: ["Plate", ...] or [["Plate", 12], ...] (label, width %)
     rows:    [["ABC123", ...], ...]  cells may be {text, cls}
   SecsysPrint.print({ title, meta: [...lines], body, footer })
========================================================= */

(function () {

    "use strict";

    var ORGANIZATION = "L. Surveillance";
    var LOCATION = "Lessandra Subdivision, Phase 2, Salinas 1, Bacoor, Cavite";
    var FOOTER = "L. Surveillance Security System";

    function esc(value) {
        return String(value === undefined || value === null ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function cssUrl() {
        // resolve relative to this script so it works from any page
        var scripts = document.getElementsByTagName("script");
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || "";
            if (src.indexOf("assets/js/print_report.js") !== -1) {
                return src.split("assets/js/print_report.js")[0] + "assets/css/print_report.css?v=" + Date.now();
            }
        }
        return new URL("assets/css/print_report.css", window.location.href).href;
    }

    function table(columns, rows, emptyText, sectionTitle) {
        var html = sectionTitle ? '<div class="section-title">' + esc(sectionTitle) + "</div>" : "";
        html += "<table><thead><tr>";
        columns.forEach(function (col) {
            if (Array.isArray(col)) {
                html += '<th style="width:' + Number(col[1]) + '%;">' + esc(col[0]) + "</th>";
            } else {
                html += "<th>" + esc(col) + "</th>";
            }
        });
        html += "</tr></thead><tbody>";
        if (!rows || rows.length === 0) {
            html += '<tr><td class="empty" colspan="' + columns.length + '">' + esc(emptyText || "No records found.") + "</td></tr>";
        } else {
            rows.forEach(function (row) {
                var cls = row && row.rowClass ? ' class="' + esc(row.rowClass) + '"' : "";
                var cells = Array.isArray(row) ? row : row.cells;
                html += "<tr" + cls + ">";
                cells.forEach(function (cell) {
                    if (cell && typeof cell === "object") {
                        html += '<td class="' + esc(cell.cls || "") + '">' + esc(cell.text) + "</td>";
                    } else {
                        html += "<td>" + esc(cell) + "</td>";
                    }
                });
                html += "</tr>";
            });
        }
        return html + "</tbody></table>";
    }

    function generatedText() {
        var d = new Date();
        return d.toLocaleDateString("en-US", { month: "long", day: "2-digit", year: "numeric" })
            + " " + d.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" });
    }

    function documentHtml(opts) {
        var meta = (opts.meta || []).map(function (line) {
            return '<div class="report-meta">' + esc(line) + "</div>";
        }).join("");

        return "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>" + esc(opts.title) + "</title>"
            + '<link rel="stylesheet" href="' + esc(cssUrl()) + '">'
            + "</head><body>"
            + '<div class="header">'
            + '<div class="organization">' + esc(ORGANIZATION) + "</div>"
            + '<div class="location">' + esc(LOCATION) + "</div>"
            + '<div class="report-title">' + esc(opts.title) + "</div>"
            + '<div class="generated">Generated: ' + esc(generatedText()) + "</div>"
            + meta
            + "</div>"
            + (opts.body || "")
            + '<div class="footer">' + esc(FOOTER) + "<br>" + esc(opts.footer || opts.title) + "</div>"
            + "</body></html>";
    }

    function print(opts) {
        var old = document.getElementById("secsysPrintFrame");
        if (old) old.remove();

        var frame = document.createElement("iframe");
        frame.id = "secsysPrintFrame";
        frame.setAttribute("aria-hidden", "true");
        frame.style.cssText = "position:fixed; right:0; bottom:0; width:0; height:0; border:0; visibility:hidden;";
        document.body.appendChild(frame);

        var doc = frame.contentWindow.document;
        doc.open();
        doc.write(documentHtml(opts));
        doc.close();

        var printed = false;
        var go = function () {
            if (printed) return;
            printed = true;
            frame.contentWindow.focus();
            frame.contentWindow.print();
        };

        // wait for the stylesheet and any images (chart) before printing
        var waitFor = Array.prototype.slice.call(doc.querySelectorAll('link[rel="stylesheet"], img'));
        var left = waitFor.length;
        if (left === 0) {
            setTimeout(go, 100);
        } else {
            var done = function () { if (--left <= 0) setTimeout(go, 100); };
            waitFor.forEach(function (el) {
                if ((el.tagName === "IMG" && el.complete) || (el.tagName === "LINK" && el.sheet)) {
                    done();
                } else {
                    el.addEventListener("load", done);
                    el.addEventListener("error", done);
                }
            });
            setTimeout(go, 2500); // safety net
        }
    }

    window.SecsysPrint = {
        table: table,
        print: print,
        documentHtml: documentHtml,
        esc: esc
    };

})();

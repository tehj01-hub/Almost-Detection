/**
 * YOLOv10 License Plate & Vehicle Capture Modal Viewer
 * Opens a modal displaying the YOLOv10 license plate crop,
 * the YOLOv10 vehicle photo, the plate number, and vehicle details
 * whenever any clickable license plate (.secsys-plate-link) is clicked.
 */
(function () {
    "use strict";

    if (window.__secsysYoloCaptureModalInitialized) {
        return;
    }
    window.__secsysYoloCaptureModalInitialized = true;

    var MODAL_ID = "secsysYoloCaptureModalOverlay";
    var STYLE_ID = "secsysYoloCaptureModalStyles";

    function ensureModalStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent =
            ".secsys-plate-link {" +
            "  display: inline-flex;" +
            "  align-items: center;" +
            "  gap: 5px;" +
            "  font-family: Consolas, 'Courier New', monospace;" +
            "  font-weight: 700;" +
            "  color: #1e3a8a;" +
            "  background: #eff6ff;" +
            "  border: 1px solid #bfdbfe;" +
            "  border-radius: 6px;" +
            "  padding: 2px 8px;" +
            "  text-decoration: none;" +
            "  cursor: pointer;" +
            "  transition: all 0.15s ease;" +
            "}" +
            ".secsys-plate-link:hover {" +
            "  background: #dbeafe;" +
            "  border-color: #60a5fa;" +
            "  color: #1d4ed8;" +
            "  transform: translateY(-1px);" +
            "  box-shadow: 0 2px 6px rgba(37, 99, 235, 0.18);" +
            "}" +
            ".secsys-plate-overlay {" +
            "  position: fixed;" +
            "  inset: 0;" +
            "  background: rgba(15, 23, 42, 0.78);" +
            "  backdrop-filter: blur(4px);" +
            "  z-index: 99999;" +
            "  display: none;" +
            "  align-items: center;" +
            "  justify-content: center;" +
            "  padding: 20px;" +
            "  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;" +
            "}" +
            ".secsys-plate-overlay.open {" +
            "  display: flex;" +
            "}" +
            ".secsys-plate-dialog {" +
            "  background: #1e293b;" +
            "  color: #f8fafc;" +
            "  border: 1px solid #334155;" +
            "  border-radius: 16px;" +
            "  max-width: 920px;" +
            "  width: 100%;" +
            "  max-height: 90vh;" +
            "  overflow-y: auto;" +
            "  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.65);" +
            "  animation: secsysModalPop 0.16s ease-out;" +
            "}" +
            "@keyframes secsysModalPop {" +
            "  from { opacity: 0; transform: scale(0.96); }" +
            "  to { opacity: 1; transform: scale(1); }" +
            "}" +
            ".secsys-plate-hdr {" +
            "  padding: 18px 22px;" +
            "  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);" +
            "  border-bottom: 1px solid #334155;" +
            "  display: flex;" +
            "  align-items: center;" +
            "  justify-content: space-between;" +
            "  gap: 14px;" +
            "  flex-wrap: wrap;" +
            "}" +
            ".secsys-plate-hero {" +
            "  display: flex;" +
            "  align-items: center;" +
            "  gap: 14px;" +
            "  flex-wrap: wrap;" +
            "}" +
            ".secsys-plate-badge-lg {" +
            "  background: #f8fafc;" +
            "  color: #0f172a;" +
            "  border: 3px solid #38bdf8;" +
            "  border-radius: 10px;" +
            "  padding: 6px 16px;" +
            "  font-family: Consolas, 'Courier New', monospace;" +
            "  font-size: 22px;" +
            "  font-weight: 900;" +
            "  letter-spacing: 2px;" +
            "  box-shadow: 0 4px 12px rgba(56, 189, 248, 0.25);" +
            "}" +
            ".secsys-plate-close {" +
            "  background: #334155;" +
            "  color: #e2e8f0;" +
            "  border: none;" +
            "  width: 34px;" +
            "  height: 34px;" +
            "  border-radius: 50%;" +
            "  font-size: 20px;" +
            "  line-height: 1;" +
            "  cursor: pointer;" +
            "  display: inline-flex;" +
            "  align-items: center;" +
            "  justify-content: center;" +
            "}" +
            ".secsys-plate-close:hover {" +
            "  background: #ef4444;" +
            "  color: #ffffff;" +
            "}" +
            ".secsys-plate-pills {" +
            "  display: flex;" +
            "  align-items: center;" +
            "  gap: 8px;" +
            "  flex-wrap: wrap;" +
            "}" +
            ".secsys-plate-pill {" +
            "  padding: 4px 11px;" +
            "  border-radius: 999px;" +
            "  font-size: 11.5px;" +
            "  font-weight: 700;" +
            "  background: #334155;" +
            "  color: #e2e8f0;" +
            "}" +
            ".secsys-plate-pill.yolo {" +
            "  background: rgba(56, 189, 248, 0.16);" +
            "  color: #38bdf8;" +
            "  border: 1px solid rgba(56, 189, 248, 0.35);" +
            "}" +
            ".secsys-plate-pill.in {" +
            "  background: rgba(34, 197, 94, 0.18);" +
            "  color: #4ade80;" +
            "  border: 1px solid rgba(34, 197, 94, 0.4);" +
            "}" +
            ".secsys-plate-pill.out {" +
            "  background: rgba(239, 68, 68, 0.18);" +
            "  color: #f87171;" +
            "  border: 1px solid rgba(239, 68, 68, 0.4);" +
            "}" +
            ".secsys-plate-switcher {" +
            "  padding: 10px 22px 0;" +
            "  display: flex;" +
            "  gap: 8px;" +
            "  flex-wrap: wrap;" +
            "}" +
            ".secsys-switch-btn {" +
            "  background: #0f172a;" +
            "  color: #94a3b8;" +
            "  border: 1px solid #334155;" +
            "  border-radius: 8px;" +
            "  padding: 5px 12px;" +
            "  font-size: 12px;" +
            "  font-weight: 600;" +
            "  cursor: pointer;" +
            "}" +
            ".secsys-switch-btn.active {" +
            "  background: #1d4ed8;" +
            "  color: #ffffff;" +
            "  border-color: #3b82f6;" +
            "}" +
            ".secsys-plate-grid {" +
            "  display: grid;" +
            "  grid-template-columns: 1fr 1.35fr;" +
            "  gap: 18px;" +
            "  padding: 20px 22px 22px;" +
            "}" +
            "@media (max-width: 740px) {" +
            "  .secsys-plate-grid { grid-template-columns: 1fr; }" +
            "}" +
            ".secsys-plate-panel {" +
            "  background: #0f172a;" +
            "  border: 1px solid #334155;" +
            "  border-radius: 12px;" +
            "  overflow: hidden;" +
            "  display: flex;" +
            "  flex-direction: column;" +
            "}" +
            ".secsys-panel-hdr {" +
            "  padding: 11px 15px;" +
            "  background: #162032;" +
            "  border-bottom: 1px solid #334155;" +
            "  font-size: 12px;" +
            "  font-weight: 700;" +
            "  color: #94a3b8;" +
            "  text-transform: uppercase;" +
            "  letter-spacing: 0.5px;" +
            "  display: flex;" +
            "  align-items: center;" +
            "  justify-content: space-between;" +
            "}" +
            ".secsys-panel-body {" +
            "  flex: 1;" +
            "  min-height: 230px;" +
            "  padding: 16px;" +
            "  background: #090d16;" +
            "  display: flex;" +
            "  flex-direction: column;" +
            "  align-items: center;" +
            "  justify-content: center;" +
            "  gap: 14px;" +
            "}" +
            ".secsys-panel-body img {" +
            "  max-width: 100%;" +
            "  max-height: 340px;" +
            "  object-fit: contain;" +
            "  border-radius: 8px;" +
            "  border: 1px solid #1e293b;" +
            "}" +
            ".secsys-panel-body img.plate-crop-img {" +
            "  max-height: 140px;" +
            "  border: 2px solid #38bdf8;" +
            "  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);" +
            "}" +
            ".secsys-readout-grid {" +
            "  width: 100%;" +
            "  background: #1e293b;" +
            "  border: 1px solid #334155;" +
            "  border-radius: 10px;" +
            "  padding: 11px 14px;" +
            "  display: grid;" +
            "  grid-template-columns: 1fr 1fr;" +
            "  gap: 9px;" +
            "  text-align: left;" +
            "}" +
            ".secsys-readout-item span {" +
            "  display: block;" +
            "  font-size: 10.5px;" +
            "  color: #64748b;" +
            "  text-transform: uppercase;" +
            "  font-weight: 700;" +
            "}" +
            ".secsys-readout-item strong {" +
            "  color: #f8fafc;" +
            "  font-size: 13.5px;" +
            "}" +
            ".secsys-empty-box {" +
            "  color: #64748b;" +
            "  font-size: 13px;" +
            "  text-align: center;" +
            "  padding: 24px 12px;" +
            "}";
        document.head.appendChild(style);
    }

    function ensureModalElement() {
        ensureModalStyles();
        var existing = document.getElementById(MODAL_ID);
        if (existing) {
            return existing;
        }
        var overlay = document.createElement("div");
        overlay.id = MODAL_ID;
        overlay.className = "secsys-plate-overlay";
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.innerHTML =
            '<div class="secsys-plate-dialog" onclick="event.stopPropagation();">' +
                '<div class="secsys-plate-hdr">' +
                    '<div class="secsys-plate-hero">' +
                        '<div class="secsys-plate-badge-lg" id="secsysModalPlateBadge">---</div>' +
                        '<div>' +
                            '<div id="secsysModalVehicleTitle" style="font-size:15px;font-weight:700;color:#f8fafc;">Vehicle</div>' +
                            '<div id="secsysModalOwnerSub" style="font-size:12.5px;color:#94a3b8;margin-top:2px;"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="secsys-plate-pills">' +
                        '<span class="secsys-plate-pill yolo">YOLOv10 Capture</span>' +
                        '<span class="secsys-plate-pill" id="secsysModalDirPill" style="display:none;"></span>' +
                        '<span class="secsys-plate-pill" id="secsysModalTimePill" style="display:none;"></span>' +
                        '<button type="button" class="secsys-plate-close" id="secsysModalCloseBtn" title="Close">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="secsys-plate-switcher" id="secsysModalCaptureSwitcher" style="display:none;"></div>' +
                '<div class="secsys-plate-grid">' +
                    '<div class="secsys-plate-panel">' +
                        '<div class="secsys-panel-hdr">' +
                            '<span>&#128269; YOLOv10 License Plate Capture</span>' +
                            '<span id="secsysModalConfLabel" style="color:#38bdf8;"></span>' +
                        '</div>' +
                        '<div class="secsys-panel-body">' +
                            '<div id="secsysModalPlateImgWrap" style="display:flex;align-items:center;justify-content:center;min-height:110px;width:100%;"></div>' +
                            '<div class="secsys-readout-grid">' +
                                '<div class="secsys-readout-item"><span>Plate Number</span><strong id="secsysReadoutPlate">-</strong></div>' +
                                '<div class="secsys-readout-item"><span>Vehicle Type</span><strong id="secsysReadoutType">-</strong></div>' +
                                '<div class="secsys-readout-item"><span>Category</span><strong id="secsysReadoutCategory">-</strong></div>' +
                                '<div class="secsys-readout-item"><span>Camera / Gate</span><strong id="secsysReadoutCamera">-</strong></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="secsys-plate-panel">' +
                        '<div class="secsys-panel-hdr">' +
                            '<span>&#128663; YOLOv10 Vehicle Capture</span>' +
                            '<span id="secsysModalVehicleSubLabel"></span>' +
                        '</div>' +
                        '<div class="secsys-panel-body" id="secsysModalVehicleImgWrap"></div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        overlay.addEventListener("click", function () {
            overlay.classList.remove("open");
        });
        document.body.appendChild(overlay);

        var closeBtn = document.getElementById("secsysModalCloseBtn");
        if (closeBtn) {
            closeBtn.addEventListener("click", function () {
                overlay.classList.remove("open");
            });
        }
        document.addEventListener("keydown", function (ev) {
            if (ev.key === "Escape" && overlay.classList.contains("open")) {
                overlay.classList.remove("open");
            }
        });

        return overlay;
    }

    function escapeHtml(str) {
        return String(str == null ? "" : str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function renderCaptureChoice(data, capIndex) {
        var cap = (data.captures && data.captures[capIndex]) ? data.captures[capIndex] : data;
        var plateUrl = cap.plate_image_url !== undefined ? cap.plate_image_url : data.plate_image_url;
        var vehUrl = cap.vehicle_image_url !== undefined ? cap.vehicle_image_url : data.vehicle_image_url;
        var dir = (cap.direction || data.direction || "").toUpperCase();
        var ts = cap.timestamp || data.timestamp || "";
        var cam = cap.camera || data.camera || (dir ? dir + " Gate" : "Gate Cam");
        var conf = cap.confidence !== undefined && cap.confidence !== null ? cap.confidence : data.confidence;

        var dirPill = document.getElementById("secsysModalDirPill");
        if (dirPill) {
            if (dir) {
                dirPill.style.display = "inline-block";
                dirPill.className = "secsys-plate-pill " + (dir === "OUT" ? "out" : "in");
                dirPill.textContent = dir === "OUT" ? "OUT (Exit)" : "IN (Entrance)";
            } else {
                dirPill.style.display = "none";
            }
        }

        var timePill = document.getElementById("secsysModalTimePill");
        if (timePill) {
            if (ts) {
                timePill.style.display = "inline-block";
                timePill.textContent = ts;
            } else {
                timePill.style.display = "none";
            }
        }

        var confLabel = document.getElementById("secsysModalConfLabel");
        if (confLabel) {
            confLabel.textContent = conf != null ? conf + "% Conf" : "";
        }

        var camReadout = document.getElementById("secsysReadoutCamera");
        if (camReadout) {
            camReadout.textContent = cam;
        }

        var plateWrap = document.getElementById("secsysModalPlateImgWrap");
        if (plateWrap) {
            if (plateUrl) {
                plateWrap.innerHTML =
                    '<a href="' + escapeHtml(plateUrl) + '" target="_blank" rel="noopener" title="Open full license plate crop">' +
                    '<img src="' + escapeHtml(plateUrl) + '" class="plate-crop-img" alt="YOLOv10 License Plate Capture">' +
                    '</a>';
            } else {
                plateWrap.innerHTML = '<div class="secsys-empty-box">No YOLOv10 license plate crop recorded for this entry.</div>';
            }
        }

        var vehWrap = document.getElementById("secsysModalVehicleImgWrap");
        if (vehWrap) {
            if (vehUrl) {
                vehWrap.innerHTML =
                    '<a href="' + escapeHtml(vehUrl) + '" target="_blank" rel="noopener" title="Open full vehicle photo">' +
                    '<img src="' + escapeHtml(vehUrl) + '" alt="YOLOv10 Vehicle Capture">' +
                    '</a>';
            } else {
                vehWrap.innerHTML = '<div class="secsys-empty-box">No YOLOv10 vehicle photo recorded for this entry.</div>';
            }
        }
    }

    function openByPlateAndIds(plate, ids) {
        var overlay = ensureModalElement();
        var cleanPlate = String(plate || "").trim();
        var cleanIds = String(ids || "").trim();

        document.getElementById("secsysModalPlateBadge").textContent = cleanPlate || "LOADING...";
        document.getElementById("secsysModalVehicleTitle").textContent = "Loading YOLOv10 capture...";
        document.getElementById("secsysModalOwnerSub").textContent = "";
        document.getElementById("secsysReadoutPlate").textContent = cleanPlate || "-";
        document.getElementById("secsysReadoutType").textContent = "-";
        document.getElementById("secsysReadoutCategory").textContent = "-";
        document.getElementById("secsysReadoutCamera").textContent = "-";
        document.getElementById("secsysModalConfLabel").textContent = "";
        document.getElementById("secsysModalVehicleSubLabel").textContent = "";
        document.getElementById("secsysModalPlateImgWrap").innerHTML = '<div class="secsys-empty-box">Loading plate image...</div>';
        document.getElementById("secsysModalVehicleImgWrap").innerHTML = '<div class="secsys-empty-box">Loading vehicle image...</div>';
        var switcher = document.getElementById("secsysModalCaptureSwitcher");
        if (switcher) {
            switcher.style.display = "none";
            switcher.innerHTML = "";
        }

        overlay.classList.add("open");

        var url = "vehicle_photo.php?format=json";
        if (cleanIds) {
            url += "&ids=" + encodeURIComponent(cleanIds);
        }
        if (cleanPlate) {
            url += "&plate=" + encodeURIComponent(cleanPlate);
        }

        fetch(url, { credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    var msg = (data && data.error) ? data.error : "Could not load YOLOv10 capture details.";
                    document.getElementById("secsysModalVehicleTitle").textContent = msg;
                    document.getElementById("secsysModalPlateImgWrap").innerHTML = '<div class="secsys-empty-box">' + escapeHtml(msg) + '</div>';
                    document.getElementById("secsysModalVehicleImgWrap").innerHTML = '<div class="secsys-empty-box">' + escapeHtml(msg) + '</div>';
                    return;
                }

                var pNum = data.plate_number || cleanPlate || "UNKNOWN";
                var vType = data.vehicle_type || "Vehicle";
                var vCat = data.category || "Vehicle";

                document.getElementById("secsysModalPlateBadge").textContent = pNum;
                document.getElementById("secsysModalVehicleTitle").textContent = vType + " \u2022 " + vCat;
                var ownerSub = data.owner_or_visitor || "";
                if (data.vehicle_model_info) {
                    ownerSub = ownerSub ? (ownerSub + " (" + data.vehicle_model_info + ")") : data.vehicle_model_info;
                }
                document.getElementById("secsysModalOwnerSub").textContent = ownerSub;

                document.getElementById("secsysReadoutPlate").textContent = pNum;
                document.getElementById("secsysReadoutType").textContent = vType;
                document.getElementById("secsysReadoutCategory").textContent = vCat;
                document.getElementById("secsysModalVehicleSubLabel").textContent = vType;

                if (switcher && Array.isArray(data.captures) && data.captures.length > 1) {
                    switcher.style.display = "flex";
                    switcher.innerHTML = "";
                    data.captures.forEach(function (cap, idx) {
                        var btn = document.createElement("button");
                        btn.type = "button";
                        btn.className = "secsys-switch-btn" + (idx === 0 ? " active" : "");
                        var dirTxt = cap.direction ? ("[" + cap.direction + "] ") : "";
                        btn.textContent = dirTxt + (cap.timestamp || ("Capture #" + (idx + 1)));
                        btn.addEventListener("click", function () {
                            var allBtns = switcher.querySelectorAll(".secsys-switch-btn");
                            for (var i = 0; i < allBtns.length; i++) {
                                allBtns[i].classList.remove("active");
                            }
                            btn.classList.add("active");
                            renderCaptureChoice(data, idx);
                        });
                        switcher.appendChild(btn);
                    });
                }

                renderCaptureChoice(data, 0);
            })
            .catch(function () {
                document.getElementById("secsysModalVehicleTitle").textContent = "Error loading capture";
            });
    }

    window.secsysOpenPlateCaptureByPlate = function (plate, ids) {
        openByPlateAndIds(plate, ids);
        return false;
    };

    window.secsysOpenPlateCaptureModal = function (event, el) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        if (!el) {
            return false;
        }
        var plate = el.getAttribute("data-plate") || el.textContent || "";
        var ids = el.getAttribute("data-ids") || "";
        openByPlateAndIds(plate, ids);
        return false;
    };

    // Delegate click events for dynamically rendered .secsys-plate-link elements
    document.addEventListener("click", function (ev) {
        var target = ev.target && ev.target.closest ? ev.target.closest(".secsys-plate-link") : null;
        if (!target) {
            return;
        }
        ev.preventDefault();
        ev.stopPropagation();
        var plate = target.getAttribute("data-plate") || target.textContent || "";
        var ids = target.getAttribute("data-ids") || "";
        openByPlateAndIds(plate, ids);
    });
})();

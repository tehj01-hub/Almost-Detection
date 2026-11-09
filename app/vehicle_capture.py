"""Vehicle photo capture with YOLOv10.

When a plate is read successfully, the frame it was read from is handed to
VehicleCapturer. A general YOLOv10 model (COCO classes: car, motorcycle,
bus, truck, bicycle) finds the vehicle the plate belongs to, and a cropped
photo of that vehicle is saved as uploads/vehicle_captures/<name>.jpg.

Work runs on a background thread so plate reading is never slowed down.
The folder is protected from direct web access; photos are served by
vehicle_photo.php to signed-in users only.
"""
import os
import queue
import threading
import time

import cv2

# COCO class id -> vehicle label
VEHICLE_CLASSES = {1: "Bicycle", 2: "Car", 3: "Motorcycle", 5: "Bus", 7: "Truck"}

_HTACCESS = "# Vehicle photos are served by vehicle_photo.php to signed-in users only\nDeny from all\n"


class VehicleCapturer:
    def __init__(self, model_path, output_dir, device="cpu", min_confidence=0.25):
        self.model_path = model_path
        self.output_dir = output_dir
        self.device = device
        self.min_confidence = min_confidence
        self.model = None
        self.available = os.path.exists(model_path)
        self.jobs = queue.Queue(maxsize=8)
        self.thread = None

    # ---------------------------------------------------------------
    def start(self):
        """Start the worker; the model loads in the background (first load takes a few seconds)."""
        if not self.available:
            print(f"[VEHICLE] Vehicle model not found at {self.model_path}; vehicle photos are disabled.")
            return
        os.makedirs(self.output_dir, exist_ok=True)
        htaccess = os.path.join(self.output_dir, ".htaccess")
        if not os.path.exists(htaccess):
            with open(htaccess, "w", encoding="utf-8") as fh:
                fh.write(_HTACCESS)
        self.thread = threading.Thread(target=self._worker, name="VehicleCapture", daemon=True)
        self.thread.start()

    def submit(self, frame, plate_bbox, name, on_saved=None):
        """Queue a capture. Never blocks; returns the relative image path it will be saved to (or None)."""
        if not self.available or frame is None or not name:
            return None
        relative = "uploads/vehicle_captures/" + name + ".jpg"
        try:
            self.jobs.put_nowait((frame, plate_bbox, name, on_saved))
        except queue.Full:
            print(f"[VEHICLE] Capture queue full; skipped vehicle photo for {name}.")
            return None
        return relative

    # ---------------------------------------------------------------
    def _load(self):
        from ultralytics import YOLOv10  # bundled yolov10 package (sys.path set by the server)
        started = time.time()
        model = YOLOv10(self.model_path)
        # Warm up once so the first real capture is fast
        import numpy as np
        model.predict(np.zeros((640, 640, 3), np.uint8), imgsz=640, verbose=False, device=self.device)
        print(f"[VEHICLE] YOLOv10 vehicle model ready ({time.time() - started:.1f}s): {os.path.basename(self.model_path)}")
        return model

    def _worker(self):
        try:
            self.model = self._load()
        except Exception as exc:
            self.available = False
            print(f"[VEHICLE] Could not load vehicle model ({exc}); vehicle photos are disabled.")
            return
        while True:
            frame, plate_bbox, name, on_saved = self.jobs.get()
            try:
                crop, info = self.locate_vehicle(frame, plate_bbox)
                relative = self._save(crop, name)
                print(f"[VEHICLE] Saved {info['label']} photo ({info['method']}): {relative}")
                if on_saved:
                    on_saved(relative, info)
            except Exception as exc:
                print(f"[VEHICLE] Vehicle photo failed for {name}: {exc}")

    # ---------------------------------------------------------------
    def locate_vehicle(self, frame, plate_bbox):
        """Return (crop, info) for the vehicle the plate belongs to.

        Picks the detected vehicle box that contains the plate's centre
        (the smallest one, if several overlap). If YOLO finds no vehicle
        around the plate, a region around the plate is used instead.
        """
        h, w = frame.shape[:2]
        px1, py1, px2, py2 = [int(v) for v in plate_bbox] if plate_bbox is not None else (0, 0, w, h)
        pcx, pcy = (px1 + px2) / 2.0, (py1 + py2) / 2.0

        best = None
        if self.model is not None:
            results = self.model.predict(frame, imgsz=640, conf=self.min_confidence,
                                         classes=list(VEHICLE_CLASSES), verbose=False, device=self.device)
            for r in results:
                for box in getattr(r, "boxes", []) or []:
                    x1, y1, x2, y2 = [float(v) for v in box.xyxy[0]]
                    cls = int(box.cls[0]) if getattr(box, "cls", None) is not None else -1
                    conf = float(box.conf[0])
                    if cls not in VEHICLE_CLASSES:
                        continue
                    if not (x1 <= pcx <= x2 and y1 <= pcy <= y2):
                        continue
                    area = (x2 - x1) * (y2 - y1)
                    if best is None or area < best[0]:
                        best = (area, (x1, y1, x2, y2), cls, conf)

        if best is not None:
            _, (x1, y1, x2, y2), cls, conf = best
            info = {"method": "yolov10", "label": VEHICLE_CLASSES[cls], "confidence": round(conf, 3)}
        else:
            # Fallback: the vehicle is above and around the plate
            pw, ph = max(1, px2 - px1), max(1, py2 - py1)
            x1, x2 = pcx - pw * 2.8, pcx + pw * 2.8
            y1, y2 = py1 - ph * 7.0, py2 + ph * 1.5
            info = {"method": "approximate", "label": "vehicle", "confidence": None}

        # Small margin, clipped to the frame
        mx, my = (x2 - x1) * 0.06, (y2 - y1) * 0.06
        x1, y1 = max(0, int(x1 - mx)), max(0, int(y1 - my))
        x2, y2 = min(w, int(x2 + mx)), min(h, int(y2 + my))
        if x2 - x1 < 16 or y2 - y1 < 16:
            x1, y1, x2, y2 = 0, 0, w, h
        return frame[y1:y2, x1:x2].copy(), info

    def _save(self, crop, name):
        ok, encoded = cv2.imencode(".jpg", crop, [cv2.IMWRITE_JPEG_QUALITY, 90])
        if not ok:
            raise OSError("JPEG encoding failed")
        final_path = os.path.join(self.output_dir, name + ".jpg")
        temp_path = final_path + ".tmp"
        with open(temp_path, "wb") as fh:
            fh.write(encoded.tobytes())
        os.replace(temp_path, final_path)
        return "uploads/vehicle_captures/" + name + ".jpg"

"""
Plate Crop Harvesting & Interactive Web Labeling Tool for Philippine Plates.

Supports:
1. Slicing plate crops using YOLOv10 (weights/best.pt) from:
   - Tapo C260 RTSP stream (/stream1)
   - Recorded MP4/AVI gate video files
   - Folder of vehicle images
2. Automatic condition tagging (daytime, nighttime, blurry, angled).
3. Built-in interactive browser labeling UI at http://127.0.0.1:5055.
"""

import argparse
import csv
import glob
import os
import sys
import threading
import time
from typing import Dict, List, Optional

import cv2
import numpy as np

# Ensure root directory is on sys.path
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from lpr_engine import PlatePreprocessor, normalize_plate_alnum


def parse_args():
    parser = argparse.ArgumentParser(description="Philippine Plate Crop Harvester & Annotation Tool")
    parser.add_argument("--source", type=str, default="0", help="RTSP URL, video file path, webcam index, or image folder")
    parser.add_argument("--output-dir", type=str, default=os.path.join(BASE_DIR, "datasets", "ph_plates"), help="Directory to save crops and annotations.csv")
    parser.add_argument("--weights", type=str, default=os.path.join(BASE_DIR, "weights", "best.pt"), help="YOLOv10 weights path")
    parser.add_argument("--conf", type=float, default=0.25, help="YOLO detection confidence threshold")
    parser.add_argument("--serve-labeler", action="store_true", help="Launch interactive browser labeling UI on port 5055")
    parser.add_argument("--port", type=int, default=5055, help="Port for web annotation interface")
    parser.add_argument("--max-crops", type=int, default=1500, help="Maximum crops to harvest")
    return parser.parse_args()


def load_yolo_model(weights_path: str):
    yolo_dir = os.path.join(BASE_DIR, "yolov10")
    if yolo_dir not in sys.path:
        sys.path.insert(0, yolo_dir)
    from ultralytics import YOLOv10
    print(f"[HARVESTER] Loading YOLOv10 detector from: {weights_path}")
    return YOLOv10(weights_path)


def harvest_crops(args):
    os.makedirs(args.output_dir, exist_ok=True)
    images_dir = os.path.join(args.output_dir, "images")
    os.makedirs(images_dir, exist_ok=True)
    csv_path = os.path.join(args.output_dir, "annotations.csv")

    existing_files = set()
    if os.path.exists(csv_path):
        with open(csv_path, "r", encoding="utf-8") as f:
            reader = csv.DictReader(f)
            for row in reader:
                existing_files.add(row.get("filename", ""))

    csv_file = open(csv_path, "a", newline="", encoding="utf-8")
    fieldnames = ["filename", "ground_truth", "raw_ocr_hint", "condition", "blur_score", "brightness", "split"]
    writer = csv.DictWriter(csv_file, fieldnames=fieldnames)
    if not existing_files:
        writer.writeheader()

    preprocessor = PlatePreprocessor()
    model = load_yolo_model(args.weights)

    # Optional EasyOCR hint
    ocr_reader = None
    try:
        import easyocr
        ocr_reader = easyocr.Reader(["en"], gpu=False)
    except Exception:
        pass

    # Source handling
    source = args.source
    is_image_folder = os.path.isdir(source)
    is_video_or_rtsp = not is_image_folder

    crop_count = len(existing_files)

    if is_image_folder:
        image_paths = sorted(glob.glob(os.path.join(source, "*.[jJ][pP][gG]")) + glob.glob(os.path.join(source, "*.[pP][nN][gG]")))
        print(f"[HARVESTER] Processing {len(image_paths)} images from folder: {source}")
        for img_p in image_paths:
            if crop_count >= args.max_crops:
                break
            frame = cv2.imread(img_p)
            if frame is None:
                continue
            crop_count = extract_and_record(frame, model, preprocessor, ocr_reader, images_dir, writer, csv_file, crop_count, args.conf)
    else:
        src_val = int(source) if source.isdigit() else source
        cap = cv2.VideoCapture(src_val)
        if not cap.isOpened():
            print(f"[HARVESTER] Error: Could not open video/RTSP source: {source}")
            return

        print(f"[HARVESTER] Capturing frames from stream: {source} (Press Ctrl+C to stop)...")
        frame_idx = 0
        try:
            while cap.isOpened() and crop_count < args.max_crops:
                ret, frame = cap.read()
                if not ret or frame is None:
                    time.sleep(0.05)
                    continue

                frame_idx += 1
                if frame_idx % 3 != 0:
                    continue  # Sample every 3rd frame to avoid excessive duplicates

                crop_count = extract_and_record(frame, model, preprocessor, ocr_reader, images_dir, writer, csv_file, crop_count, args.conf)
        finally:
            cap.release()

    csv_file.close()
    print(f"\n[HARVESTER] Finished. Total crops collected: {crop_count} in {images_dir}")


def extract_and_record(frame, model, preprocessor, ocr_reader, images_dir, writer, csv_file, crop_count, conf_thresh):
    h, w = frame.shape[:2]
    results = model.predict(source=frame, imgsz=640, conf=conf_thresh, verbose=False)

    for r in results:
        if r.boxes is None or len(r.boxes) == 0:
            continue
        for b in r.boxes:
            x1, y1, x2, y2 = map(int, b.xyxy[0].tolist())
            pad_x = int((x2 - x1) * 0.06)
            pad_y = int((y2 - y1) * 0.08)
            cx1 = max(0, x1 - pad_x)
            cy1 = max(0, y1 - pad_y)
            cx2 = min(w, x2 + pad_x)
            cy2 = min(h, y2 + pad_y)

            crop = frame[cy1:cy2, cx1:cx2]
            quality = preprocessor.assess_quality(crop)
            if not quality.is_usable:
                continue

            crop_count += 1
            filename = f"plate_{crop_count:05d}.jpg"
            filepath = os.path.join(images_dir, filename)
            cv2.imwrite(filepath, crop)

            ocr_hint = ""
            if ocr_reader:
                try:
                    res = ocr_reader.readtext(crop, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
                    if res:
                        ocr_hint = normalize_plate_alnum(" ".join(t[1] for t in res))
                except Exception:
                    pass

            condition = ";".join(quality.condition_tags) if quality.condition_tags else "standard"
            split = "train" if (crop_count % 5 != 0) else "val"

            writer.writerow({
                "filename": filename,
                "ground_truth": ocr_hint,  # Default to hint, edited via web labeler
                "raw_ocr_hint": ocr_hint,
                "condition": condition,
                "blur_score": quality.blur_score,
                "brightness": quality.mean_brightness,
                "split": split,
            })
            csv_file.flush()
            print(f"[CROP #{crop_count}] Saved {filename} | {quality.width}x{quality.height} | {condition} | hint: {ocr_hint}")

    return crop_count


def launch_web_labeler(output_dir: str, port: int = 5055):
    from flask import Flask, jsonify, render_template_string, request, send_from_directory

    app = Flask(__name__)
    images_dir = os.path.join(output_dir, "images")
    csv_path = os.path.join(output_dir, "annotations.csv")

    HTML_TEMPLATE = """
    <!DOCTYPE html>
    <html>
    <head>
        <title>Philippine Plate Labeler - Lessandra Subdivision</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; text-align: center; margin: 0; padding: 24px; }
            .card { background: #1e293b; max-width: 650px; margin: 0 auto; padding: 24px; border-radius: 12px; box-shadow: 0 4px 14px rgba(0,0,0,0.4); }
            img { max-width: 90%; height: 90px; object-fit: contain; border-radius: 6px; border: 2px solid #38bdf8; background: #000; margin: 16px 0; }
            input[type=text] { width: 80%; font-size: 24px; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 3px; padding: 10px; border-radius: 8px; border: 2px solid #475569; background: #0f172a; color: #38bdf8; }
            .btn { background: #22c55e; color: #fff; font-size: 18px; font-weight: bold; padding: 10px 24px; border: none; border-radius: 8px; cursor: pointer; margin-top: 16px; }
            .btn:hover { background: #16a34a; }
            .meta { color: #94a3b8; font-size: 13px; margin: 8px 0; }
            .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; background: #334155; font-size: 12px; margin: 2px; }
            .conf-note { color: #f59e0b; font-size: 13px; font-weight: 500; margin-top: 8px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>🚗 Philippine Plate Labeling Tool</h2>
            <div id="content">Loading crops...</div>
        </div>
        <script>
            let current = null;
            async function loadNext() {
                const res = await fetch('/api/next');
                current = await res.json();
                if (!current || !current.filename) {
                    document.getElementById('content').innerHTML = '<h3 style="color:#22c55e;">🎉 All plates labeled!</h3>';
                    return;
                }
                document.getElementById('content').innerHTML = `
                    <div class="meta"><b>File:</b> ${current.filename} (${current.remaining} remaining)</div>
                    <img src="/crop/${current.filename}" alt="Crop" />
                    <div>
                        <input type="text" id="plateInput" value="${current.ground_truth || current.raw_ocr_hint || ''}" autocomplete="off" />
                    </div>
                    <div class="conf-note">Verify confusable characters: <b>Z vs 2</b>, <b>B vs 8</b>, <b>D vs 0</b>, <b>S vs 5</b></div>
                    <div class="meta">
                        Condition: <span class="badge">${current.condition}</span>
                        Blur: <span class="badge">${current.blur_score}</span>
                        Split: <span class="badge">${current.split}</span>
                    </div>
                    <button class="btn" onclick="saveLabel()">Save & Next (Enter)</button>
                `;
                const inp = document.getElementById('plateInput');
                inp.focus();
                inp.select();
                inp.onkeydown = (e) => { if (e.key === 'Enter') saveLabel(); };
            }
            async function saveLabel() {
                const val = document.getElementById('plateInput').value.trim();
                await fetch('/api/save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ filename: current.filename, ground_truth: val })
                });
                loadNext();
            }
            loadNext();
        </script>
    </body>
    </html>
    """

    @app.route("/")
    def index():
        return render_template_string(HTML_TEMPLATE)

    @app.route("/crop/<path:filename>")
    def get_crop(filename):
        return send_from_directory(images_dir, filename)

    @app.route("/api/next")
    def api_next():
        if not os.path.exists(csv_path):
            return jsonify({})
        with open(csv_path, "r", encoding="utf-8") as f:
            rows = list(csv.DictReader(f))
        unlabeled = [r for r in rows if not r.get("ground_truth")]
        target = unlabeled[0] if unlabeled else (rows[-1] if rows else {})
        res = dict(target)
        res["remaining"] = len(unlabeled)
        return jsonify(res)

    @app.route("/api/save", methods=["POST"])
    def api_save():
        data = request.json or {}
        fname = data.get("filename")
        label = normalize_plate_alnum(data.get("ground_truth", ""))
        if not os.path.exists(csv_path):
            return jsonify({"success": False})

        with open(csv_path, "r", encoding="utf-8") as f:
            rows = list(csv.DictReader(f))
        fieldnames = ["filename", "ground_truth", "raw_ocr_hint", "condition", "blur_score", "brightness", "split"]
        for r in rows:
            if r.get("filename") == fname:
                r["ground_truth"] = label
                break

        with open(csv_path, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=fieldnames)
            w.writeheader()
            w.writerows(rows)
        return jsonify({"success": True})

    print(f"\n[LABELER] Interactive web labeling server active at: http://127.0.0.1:{port}")
    app.run(host="0.0.0.0", port=port, debug=False)


if __name__ == "__main__":
    cli_args = parse_args()
    if cli_args.serve_labeler and not os.path.exists(os.path.join(cli_args.output_dir, "annotations.csv")):
        t = threading.Thread(target=harvest_crops, args=(cli_args,), daemon=True)
        t.start()
        launch_web_labeler(cli_args.output_dir, cli_args.port)
    elif cli_args.serve_labeler:
        launch_web_labeler(cli_args.output_dir, cli_args.port)
    else:
        harvest_crops(cli_args)

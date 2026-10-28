"""
=============================================================================
Quick Test Script: YOLOv10 License Plate Detection + OCR
=============================================================================
Usage:
    python tests/test_plate_detection.py
    python tests/test_plate_detection.py "path/to/any_car_image.jpg"
=============================================================================
"""

import os
import sys
import glob
import cv2
import torch
import torch.serialization

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Ensure local yolov10 is importable
yolov10_dir = os.path.join(PROJECT_ROOT, "yolov10")
if yolov10_dir not in sys.path:
    sys.path.insert(0, yolov10_dir)

# PyTorch 2.6+ recursion-safe patch
try:
    from ultralytics.nn.tasks import YOLOv10DetectionModel
    torch.serialization.add_safe_globals([YOLOv10DetectionModel])
except Exception:
    pass

def _safe_torch_load(*args, **kwargs):
    if "weights_only" not in kwargs:
        kwargs["weights_only"] = False
    return torch.serialization.load(*args, **kwargs)

torch.load = _safe_torch_load

from ultralytics import YOLOv10
import easyocr

print("=" * 60)
print("Testing YOLOv10 License Plate Detection")
print("=" * 60)

# 1. Determine Model Path
model_path = os.path.join(PROJECT_ROOT, "weights", "best.pt")
fallback_path = os.path.join(PROJECT_ROOT, "yolov10", "weights", "yolov10s.pt")

if os.path.exists(model_path):
    print(f"[OK] Using trained weights: {model_path}")
elif os.path.exists(fallback_path):
    print(f"[NOTICE] Custom 'weights/best.pt' not found yet.")
    print(f"[NOTICE] Testing with fallback pretrained weights: {fallback_path}")
    model_path = fallback_path
else:
    print("[ERROR] No model weights found!")
    print("Please place your downloaded 'best.pt' inside the 'weights/' folder.")
    sys.exit(1)

# 2. Select Test Image
if len(sys.argv) > 1 and os.path.exists(sys.argv[1]):
    image_path = sys.argv[1]
else:
    # Pick the first image from test dataset
    test_images = glob.glob(os.path.join(PROJECT_ROOT, "numberplate_dataset", "test", "images", "*.*"))
    if not test_images:
        test_images = glob.glob(os.path.join(PROJECT_ROOT, "numberplate_dataset", "valid", "images", "*.*"))
    if not test_images:
        print("[ERROR] No sample test images found in 'numberplate_dataset/test/images/'.")
        print("Please provide an image: python tests/test_plate_detection.py <image_path>")
        sys.exit(1)
    image_path = test_images[0]

print(f"[INFO] Testing on image: {image_path}")

# 3. Load Model & OCR
print("[INFO] Loading YOLOv10 model...")
model = YOLOv10(model_path)

print("[INFO] Loading EasyOCR...")
reader = easyocr.Reader(['en'], gpu=torch.cuda.is_available())

# 4. Run Detection
img = cv2.imread(image_path)
if img is None:
    print(f"[ERROR] Could not read image at {image_path}")
    sys.exit(1)

results = model.predict(source=img, conf=0.25, imgsz=640, verbose=False)[0]

print(f"\n[RESULTS] Found {len(results.boxes)} plate detection(s):")

for idx, box in enumerate(results.boxes):
    xyxy = box.xyxy[0].cpu().numpy().astype(int)
    conf = float(box.conf[0])
    x1, y1, x2, y2 = xyxy

    # Crop detected plate
    plate_crop = img[y1:y2, x1:x2]
    plate_text = ""
    ocr_conf = 0.0

    if plate_crop.size > 0:
        ocr_results = reader.readtext(plate_crop)
        if ocr_results:
            plate_text = ocr_results[0][1].strip()
            ocr_conf = float(ocr_results[0][2])

    print(f"  Plate #{idx+1}:")
    print(f"    - Bounding Box : [{x1}, {y1}, {x2}, {y2}]")
    print(f"    - YOLO Conf    : {conf:.2%}")
    print(f"    - OCR Text     : '{plate_text}' (OCR Conf: {ocr_conf:.2%})")

    # Draw box & label on image
    cv2.rectangle(img, (x1, y1), (x2, y2), (0, 255, 0), 2)
    label = f"{plate_text} ({conf:.0%})" if plate_text else f"Plate ({conf:.0%})"
    cv2.putText(img, label, (x1, max(y1 - 10, 20)), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)

# 5. Save output
output_file = os.path.join(PROJECT_ROOT, "tests", "artifacts", "test_result.jpg")
os.makedirs(os.path.dirname(output_file), exist_ok=True)
cv2.imwrite(output_file, img)
print(f"\n[SUCCESS] Annotated image saved to: {output_file}")
print("=" * 60)

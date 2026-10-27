import os
import sys
import cv2
import numpy as np

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

base_cache = os.path.join(BASE_DIR, '.paddle_cache')
os.environ['PADDLE_HOME'] = base_cache
os.environ['PADDLEX_HOME'] = base_cache

import easyocr
from paddleocr import PaddleOCR
from lpr_engine import LPRNetRecognizer
from lpr_engine.triple_ensemble import TripleReaderEnsemble

easyocr_model_dir = os.path.join(BASE_DIR, "weights", "easyocr")

print("Initializing 1/3: EasyOCR...")
easy_reader = easyocr.Reader(['en'], gpu=False, model_storage_directory=easyocr_model_dir, user_network_directory=easyocr_model_dir)

print("Initializing 2/3: PaddleOCR...")
paddle_reader = PaddleOCR(use_angle_cls=False, lang='en')

print("Initializing 3/3: LPRNet...")
lpr_recognizer = LPRNetRecognizer()

ensemble = TripleReaderEnsemble(
    easyocr_reader=easy_reader,
    paddleocr_reader=paddle_reader,
    lprnet_recognizer=lpr_recognizer
)

# Create synthetic high-contrast Philippine license plate image "D566CP"
img = np.full((80, 240, 3), 255, dtype=np.uint8)
# Add black border
cv2.rectangle(img, (4, 4), (235, 75), (0, 0, 0), 2)
# Draw text "D566CP"
cv2.putText(img, "D566CP", (25, 55), cv2.FONT_HERSHEY_SIMPLEX, 1.4, (0, 0, 0), 3, cv2.LINE_AA)

registered_db = {
    "D566CP": {"id": 15, "vehicle_type": "Car"}
}

print("Running Triple-Reader Ensemble on synthetic plate 'D566CP'...")
res = ensemble.recognize_ensemble(img, registered_plates=registered_db)

print(f"\n==========================================")
print(f"TRIPLE-READER ENSEMBLE ARBITRATION RESULT:")
print(f"==========================================")
print(f"  Decision: {res.decision}")
print(f"  Final Plate: {res.final_plate}")
print(f"  Display: {res.display_plate}")
print(f"  Confidence: {res.confidence:.1%}")
print(f"  Method: {res.method}")
print(f"  Quality (sharpness={res.quality.sharpness:.1f}, usable={res.quality.is_usable})")
for m_name, pred in res.model_predictions.items():
    print(f"  Model [{m_name}]: raw='{pred.get('raw')}', plate='{pred.get('plate')}', conf={pred.get('conf', 0):.2f}")


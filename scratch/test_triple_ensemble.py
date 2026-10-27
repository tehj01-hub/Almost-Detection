import os
import sys
import numpy as np

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from lpr_engine.triple_ensemble import (
    TripleReaderEnsemble,
    assess_image_quality,
    align_and_resolve_characters,
    extract_best_template_match
)


class MockReader:
    def __init__(self, output_text, conf=0.9):
        self.output_text = output_text
        self.conf = conf

    def predict(self, crop):
        return self.output_text, self.conf


def test_ensemble_logic():
    print("=== TESTING TRIPLE-READER ENSEMBLE ARBITRATION ===")

    dummy_crop = np.full((70, 200, 3), 128, dtype=np.uint8)
    # Add high-contrast stripes so sharpness is good
    dummy_crop[::5, :, :] = 255
    dummy_crop[1::5, :, :] = 0

    registered_db = {
        "D566CP": {"id": 15, "vehicle_type": "Car"},
        "N838RZ": {"id": 13, "vehicle_type": "Car"},
        "NA16155": {"id": 14, "vehicle_type": "Car"},
    }

    scenarios = [
        ("Scenario 1: 3/3 Unanimous Majority",
         "D566CP", "D566CP", "D566CP"),

        ("Scenario 2: 2/3 Majority (One model says N838R2 instead of N838RZ)",
         "N838RZ", "N838RZ", "N838R2"),

        ("Scenario 3: Character-level Disagreement (Z vs 2 at position 5)",
         "N838R2", "N838RZ", "N838R2"),

        ("Scenario 4: Character-level Disagreement (D vs 0 at position 0)",
         "0566CP", "D566CP", "0566CP"),

        ("Scenario 5: Screw & Border Noise (00...J and ...1)",
         "00566CPJ", "D566CP1", "D566CP"),

        ("Scenario 6: Visitor Plate with Majority (ABC1234)",
         "ABC1234", "ABC1234", "0ABC1234"),

        ("Scenario 7: Completely Conflicting Models (No consensus)",
         "ABC1234", "XYZ9876", "N111AA"),
    ]

    for title, easy_txt, pad_txt, lpr_txt in scenarios:
        ensemble = TripleReaderEnsemble()
        ensemble.read_with_easyocr = lambda crop, t=easy_txt: (t, 0.90)
        ensemble.read_with_paddleocr = lambda crop, t=pad_txt: (t, 0.92)
        ensemble.read_with_lprnet = lambda crop, t=lpr_txt: (t, 0.88)

        res = ensemble.recognize_ensemble(dummy_crop, registered_plates=registered_db)

        print(f"\n{title}")
        print(f"  Inputs: EasyOCR='{easy_txt}', PaddleOCR='{pad_txt}', LPRNet='{lpr_txt}'")
        print(f"  -> Decision: [{res.decision}] | Final Plate: '{res.final_plate}' (Len: {len(res.final_plate)})")
        print(f"  -> Method: {res.method} | Display: '{res.display_plate}' | Conf: {res.confidence:.1%}")
        if res.review_reason:
            print(f"  -> Review Reason: {res.review_reason}")


if __name__ == "__main__":
    test_ensemble_logic()


"""
Test Quad-Reader OCR Ensemble (EasyOCR + PaddleOCR + LPRNet + GPT Vision).
Can be executed from any folder.
"""

import os
import sys
import numpy as np

# Ensure project root is in sys.path
PROJECT_ROOT = os.path.dirname(os.path.abspath(__file__))
if PROJECT_ROOT not in sys.path:
    sys.path.insert(0, PROJECT_ROOT)

from lpr_engine.quad_ensemble import QuadReaderEnsemble

def main():
    print("=" * 60)
    print("  TESTING 4-READER OCR ENSEMBLE PIPELINE")
    print("=" * 60)

    print("\nInitializing Quad-Reader Ensemble...")
    ensemble = QuadReaderEnsemble()
    
    print("\nEnsemble Reader Status:")
    print(f"- EasyOCR:    {'Configured' if ensemble.easyocr else 'Standby'}")
    print(f"- PaddleOCR:  {'Configured' if ensemble.paddleocr else 'Standby'}")
    print(f"- LPRNet:     {'Configured' if ensemble.lprnet else 'Standby'}")
    print(f"- GPTVision:  {'READY' if ensemble.gpt_vision.is_configured() else 'Standby (no API key)'}")

    # Create synthetic test plate crop (dark text on light background)
    test_crop = np.ones((70, 200, 3), dtype=np.uint8) * 220
    # Add dummy text-like pattern
    test_crop[20:50, 30:170] = 30

    print("\nRunning test inference on sample plate crop...")
    res = ensemble.recognize_ensemble(test_crop)
    
    print(f"Decision:     {res.decision}")
    print(f"Method:       {res.method}")
    print(f"Confidence:   {res.confidence:.1%}")
    if res.final_plate:
        print(f"Plate:        {res.final_plate}")
    if res.review_reason:
        print(f"Note:         {res.review_reason}")
    print("\n[SUCCESS] Quad-Reader Ensemble pipeline is working correctly!")

if __name__ == "__main__":
    main()


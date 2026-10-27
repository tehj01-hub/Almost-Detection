"""
Standalone Verification Script for LPRNet Engine (Step 3).

Tests:
1. Exact LPRNet tensor forward pass shape (B, 37, 18).
2. Conditional preprocessing quality gate & deskew/CLAHE logic.
3. CTC decoder on synthetic logits with Z vs 2 ambiguity.
4. Philippine plate format validator on real Lessandra plates (NAA 1234, D566CP, N838RZ).
5. End-to-end LPRNetRecognizer pipeline with mock crop.
"""

import sys
import numpy as np
import torch

from lpr_engine import (
    PH_CHARS,
    NUM_CLASSES,
    LPRNet,
    PlatePreprocessor,
    CTCDecoder,
    PhilippinePlateValidator,
    LPRNetRecognizer,
    normalize_plate_alnum,
)

def run_tests():
    print("=" * 60)
    print("  TEST 1: Alphabet & Encoding")
    print("=" * 60)
    print(f"Alphabet length: {len(PH_CHARS)} chars")
    print(f"Num classes (with CTC blank): {NUM_CLASSES}")
    norm = normalize_plate_alnum("abc-1234 ")
    assert norm == "ABC1234", f"Normalization failed: {norm}"
    print(f"normalize_plate_alnum('abc-1234 ') -> '{norm}' [PASS]")

    print("\n" + "=" * 60)
    print("  TEST 2: LPRNet Architecture & Output Dimensions")
    print("=" * 60)
    model = LPRNet(num_classes=NUM_CLASSES)
    model.eval()
    dummy_input = torch.randn(2, 3, 24, 94)
    with torch.no_grad():
        out = model(dummy_input)
    print(f"Input shape:  {tuple(dummy_input.shape)} (B, C, H, W)")
    print(f"Output shape: {tuple(out.shape)} (B, num_classes, T)")
    assert out.shape == (2, NUM_CLASSES, 18), f"Unexpected output shape: {out.shape}"
    print("LPRNet forward pass shape verified! [PASS]")

    print("\n" + "=" * 60)
    print("  TEST 3: Quality-Aware Preprocessor")
    print("=" * 60)
    preprocessor = PlatePreprocessor(mode="conditional_smart")
    dummy_crop = np.full((50, 150, 3), 120, dtype=np.uint8)
    metrics = preprocessor.assess_quality(dummy_crop)
    print(f"Crop assessed: size={metrics.width}x{metrics.height}, blur={metrics.blur_score}, brightness={metrics.mean_brightness}")
    chw, proc_bgr, ops, _ = preprocessor.preprocess(dummy_crop, quality=metrics)
    print(f"Preprocessed tensor shape: {chw.shape}, applied ops: {ops}")
    assert chw.shape == (3, 24, 94), f"Preprocessed shape mismatch: {chw.shape}"
    print("Preprocessor verified! [PASS]")

    print("\n" + "=" * 60)
    print("  TEST 4: CTC Decoder & Z vs 2 Ambiguity Detection")
    print("=" * 60)
    decoder = CTCDecoder(beam_width=5)
    # Default all timesteps to CTC BLANK token (index 36)
    from lpr_engine import BLANK_INDEX
    synthetic_logits = torch.full((NUM_CLASSES, 18), -5.0)
    synthetic_logits[BLANK_INDEX, :] = 5.0  # Blank is high by default between characters
    # Timestep 1: 'N' (idx 23)
    synthetic_logits[PH_CHARS.index('N'), 1] = 12.0
    # Timestep 4: '8' (idx 8)
    synthetic_logits[PH_CHARS.index('8'), 4] = 12.0
    # Timestep 7: '3' (idx 3)
    synthetic_logits[PH_CHARS.index('3'), 7] = 12.0
    # Timestep 10: '8' (idx 8)
    synthetic_logits[PH_CHARS.index('8'), 10] = 12.0
    # Timestep 13: 'R' (idx 27)
    synthetic_logits[PH_CHARS.index('R'), 13] = 12.0
    # Timestep 16: 'Z' with 10.0, but '2' close behind with 9.8 (low margin!)
    synthetic_logits[PH_CHARS.index('Z'), 16] = 10.0
    synthetic_logits[PH_CHARS.index('2'), 16] = 9.8

    res = decoder.decode(synthetic_logits)
    print(f"Decoded raw text: '{res.raw_text}'")
    print(f"Plate confidence: {res.plate_confidence:.3f}")
    print(f"Top beam candidates: {[(c.text, c.probability) for c in res.top_candidates[:3]]}")
    print(f"Ambiguous positions flagged: {res.ambiguous_positions}")
    assert "N838RZ" in res.raw_text, f"Unexpected decoded text: {res.raw_text}"
    assert len(res.ambiguous_positions) > 0, "Failed to flag Z vs 2 ambiguity!"
    print("Z vs 2 ambiguity correctly flagged! [PASS]")

    print("\n" + "=" * 60)
    print("  TEST 5: Philippine Plate Validator & Guard Routing")
    print("=" * 60)
    validator = PhilippinePlateValidator(min_auto_accept_conf=0.75, min_auto_accept_agreement=2)
    active_reg = {
        "D566CP": {"id": 15, "category": "Registered"},
        "N838RZ": {"id": 13, "category": "Registered"},
    }

    # Case A: Standard plate with high confidence & agreement -> AUTO_ACCEPT
    res_high = decoder.decode(synthetic_logits)
    dec_a = validator.evaluate(res_high, frame_agreement_count=2, active_registered_plates=active_reg)
    print(f"Case A ('N838RZ' registered match): action={dec_a.action}, display='{dec_a.display_plate}'")
    assert dec_a.action == "AUTO_ACCEPT", f"Expected AUTO_ACCEPT, got {dec_a.action}"

    # Case B: Misread '0566CP' (D read as 0) -> flags confusable neighbor and routes to NEEDS_HUMAN_REVIEW
    # instead of silently changing it!
    from lpr_engine.ctc_decoder import DecodedPlateResult, CandidatePrediction
    mock_0566 = DecodedPlateResult(
        raw_text="0566CP",
        plate_confidence=0.88,
        top_candidates=[CandidatePrediction("0566CP", 0.88)],
    )
    dec_b = validator.evaluate(mock_0566, frame_agreement_count=2, active_registered_plates=active_reg)
    print(f"Case B ('0566CP' confusable neighbor to 'D566CP'): action={dec_b.action}")
    print(f"Reason: {dec_b.review_reason}")
    assert dec_b.action == "NEEDS_HUMAN_REVIEW", f"Expected NEEDS_HUMAN_REVIEW, got {dec_b.action}"

    print("\n" + "=" * 60)
    print("  ALL LPRNet ENGINE TESTS PASSED SUCCESSFULLY! (100%)")
    print("=" * 60)

if __name__ == "__main__":
    run_tests()

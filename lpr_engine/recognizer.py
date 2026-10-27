"""
High-Level Unified LPRNet Recognizer Pipeline.

Combines:
- Quality assessment & conditional preprocessing
- LPRNet PyTorch forward pass (CUDA with CPU fallback)
- CTC greedy + prefix beam search decoding
- Philippine format validation & ambiguity decision
"""

from dataclasses import dataclass
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np
import torch

from .ctc_decoder import CTCDecoder, DecodedPlateResult
from .lprnet_model import LPRNet, load_lprnet_checkpoint, select_inference_device
from .ph_plate_validator import PhilippinePlateValidator, ValidationDecision
from .preprocessing import CropQualityMetrics, PlatePreprocessor


@dataclass
class RecognitionOutput:
    raw_text: str
    display_text: str
    confirmed_plate: str
    action: str  # "AUTO_ACCEPT", "NEEDS_HUMAN_REVIEW", "REJECT_NOISE"
    confidence: float
    char_confidences: List[float]
    top_candidates: List[Dict[str, object]]
    ambiguous_positions: List[Dict[str, object]]
    matched_format: Optional[str]
    applied_preprocessing: List[str]
    quality_metrics: CropQualityMetrics
    review_reason: Optional[str] = None


class LPRNetRecognizer:
    """
    End-to-end character recognizer for cropped license plate regions.
    """

    def __init__(
        self,
        checkpoint_path: Optional[str] = None,
        preprocessing_mode: str = "conditional_smart",
        device: Optional[torch.device] = None,
        beam_width: int = 5,
        min_auto_accept_conf: float = 0.75,
        min_auto_accept_agreement: int = 2,
    ) -> None:
        self.device = device or select_inference_device()
        self.preprocessor = PlatePreprocessor(mode=preprocessing_mode)
        self.decoder = CTCDecoder(beam_width=beam_width)
        self.validator = PhilippinePlateValidator(
            min_auto_accept_conf=min_auto_accept_conf,
            min_auto_accept_agreement=min_auto_accept_agreement,
        )

        if checkpoint_path is not None:
            self.model, self.meta = load_lprnet_checkpoint(checkpoint_path, device=self.device)
        else:
            self.model = LPRNet()
            self.model.to(self.device)
            self.model.eval()
            self.meta = {"status": "uninitialized"}

    def recognize(
        self,
        bgr_crop: np.ndarray,
        frame_agreement_count: int = 1,
        active_registered_plates: Optional[Dict[str, Dict[str, object]]] = None,
    ) -> RecognitionOutput:
        quality = self.preprocessor.assess_quality(bgr_crop)

        if not quality.is_usable:
            return RecognitionOutput(
                raw_text="",
                display_text="",
                confirmed_plate="",
                action="REJECT_NOISE",
                confidence=0.0,
                char_confidences=[],
                top_candidates=[],
                ambiguous_positions=[],
                matched_format=None,
                applied_preprocessing=["quality_rejection"],
                quality_metrics=quality,
                review_reason=f"Plate crop unusable: blur_score={quality.blur_score}, size={quality.width}x{quality.height}",
            )

        tensor_np, _, ops, _ = self.preprocessor.preprocess(bgr_crop, quality=quality)
        inp = torch.from_numpy(tensor_np).unsqueeze(0).to(self.device)

        with torch.no_grad():
            logits = self.model(inp)  # (1, 37, 18)

        decoded: DecodedPlateResult = self.decoder.decode(logits[0], is_probabilities=False)
        decision: ValidationDecision = self.validator.evaluate(
            decoded=decoded,
            frame_agreement_count=frame_agreement_count,
            active_registered_plates=active_registered_plates,
        )

        candidates_data = [
            {"text": c.text, "probability": c.probability}
            for c in decoded.top_candidates
        ]

        return RecognitionOutput(
            raw_text=decoded.raw_text,
            display_text=decision.display_plate,
            confirmed_plate=decision.confirmed_plate,
            action=decision.action,
            confidence=decoded.plate_confidence,
            char_confidences=decoded.char_confidences,
            top_candidates=candidates_data,
            ambiguous_positions=decoded.ambiguous_positions,
            matched_format=decision.matched_format,
            applied_preprocessing=ops,
            quality_metrics=quality,
            review_reason=decision.review_reason,
        )


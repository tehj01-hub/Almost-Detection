"""
Philippine License Plate Recognition Engine (LPRNet + Conditional Preprocessing + Format Validator).

Thesis Project:
    License Plate Recognition Based Vehicle Logging System for Lessandra Subdivision,
    Phase 2, Salinas 1, Bacoor, Cavite.
"""

from .alphabet import (
    PH_CHARS,
    BLANK_CHAR,
    BLANK_INDEX,
    NUM_CLASSES,
    CHAR_TO_IDX,
    IDX_TO_CHAR,
    CONFUSABLE_PEERS,
    encode_plate_text,
    normalize_plate_alnum,
)
from .lprnet_model import LPRNet, select_inference_device, load_lprnet_checkpoint, save_lprnet_checkpoint
from .preprocessing import PlatePreprocessor, CropQualityMetrics
from .ctc_decoder import CTCDecoder, DecodedPlateResult, CandidatePrediction
from .ph_plate_validator import PhilippinePlateValidator, ValidationDecision
from .recognizer import LPRNetRecognizer, RecognitionOutput
from .gpt_vision_reader import GPTVisionReader
from .quad_ensemble import QuadReaderEnsemble, QuadEnsembleResult, TripleReaderEnsemble, TripleEnsembleResult

__all__ = [
    "PH_CHARS",
    "BLANK_CHAR",
    "BLANK_INDEX",
    "NUM_CLASSES",
    "CHAR_TO_IDX",
    "IDX_TO_CHAR",
    "CONFUSABLE_PEERS",
    "encode_plate_text",
    "normalize_plate_alnum",
    "LPRNet",
    "select_inference_device",
    "load_lprnet_checkpoint",
    "save_lprnet_checkpoint",
    "PlatePreprocessor",
    "CropQualityMetrics",
    "CTCDecoder",
    "DecodedPlateResult",
    "CandidatePrediction",
    "PhilippinePlateValidator",
    "ValidationDecision",
    "LPRNetRecognizer",
    "RecognitionOutput",
    "GPTVisionReader",
    "QuadReaderEnsemble",
    "QuadEnsembleResult",
    "TripleReaderEnsemble",
    "TripleEnsembleResult",
]


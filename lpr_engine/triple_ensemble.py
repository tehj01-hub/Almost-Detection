"""
Triple & Quad-Reader OCR Ensemble & Character-Level Arbitration Engine.
Readers:
1. EasyOCR (Spatial token reader + contrast enhancement)
2. PaddleOCR (PP-OCR high-precision character recognition)
3. LPRNet (Specialized deep CNN license plate character sequence reader)
4. GPT Vision (Multimodal GPT-4o / GPT-4o-mini Vision with visual ambiguity resolution)
"""

# Export all symbols from quad_ensemble for seamless backward compatibility
from .quad_ensemble import (
    DIGIT_TO_LETTER,
    LETTER_TO_DIGIT,
    PH_TEMPLATES,
    QualityMetrics,
    CharacterPositionAnalysis,
    QuadEnsembleResult,
    TripleEnsembleResult,
    assess_image_quality,
    score_and_fix_syntax,
    extract_best_template_match,
    align_and_resolve_characters,
    QuadReaderEnsemble,
    TripleReaderEnsemble,
)

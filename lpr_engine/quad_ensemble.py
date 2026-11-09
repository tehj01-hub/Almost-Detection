"""
Quad-Reader OCR Ensemble & Character-Level Arbitration Engine.
Readers:
1. EasyOCR (Spatial token reader + contrast enhancement)
2. PaddleOCR (PP-OCR high-precision character recognition)
3. LPRNet (Specialized deep CNN license plate character sequence reader)
4. GPT Vision (Multimodal GPT-4o / GPT-4o-mini Vision with visual ambiguity resolution)

Arbitration Rules:
- Majority Voting (4/4 unanimous, 3/4 majority, 2/4 plurality, and 2-vs-2 tie-break)
- Character-Level Positional Comparison & Disambiguation (Z/2, D/0, B/8, S/5, I/1)
- Image Quality & Calibrated Confidence Gates (Sharpness, Brightness, Contrast)
- Accept vs Review Decision in Python (Auto-accept or route to security review)
- Graceful Standby: If GPT Vision API key is not configured, seamlessly falls back to 3-reader voting.
"""

from dataclasses import dataclass, field
from .reader_pool import BoundedReaderPool
import json
import os
import re
import sys
import time
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np
import torch

from .alphabet import are_confusable_neighbors, normalize_plate_alnum
from .ph_plate_validator import NON_PLATE_WORDS
from .preprocessing import PlatePreprocessor
from .gpu_char_extractor import GPUCharacterExtractor
from .gpt_vision_reader import GPTVisionReader


# Positional mappings based on Philippine LTO plate standards
DIGIT_TO_LETTER = {
    '0': 'D',
    '1': 'I',
    '2': 'Z',
    '4': 'A',
    '5': 'S',
    '6': 'G',
    '7': 'T',
    '8': 'B',
}

LETTER_TO_DIGIT = {
    'O': '0',
    'D': '0',
    'Q': '0',
    'I': '1',
    'L': '1',
    'T': '1',
    'Z': '2',
    'A': '4',
    'S': '5',
    'G': '6',
    'B': '8',
    'R': '8',
}

# Philippine Plate Templates:
# (name, length, letter_indices, digit_indices, formatter)
PH_TEMPLATES = [
    ('conduction', 6, [0, 4, 5], [1, 2, 3], lambda s: f"{s[0]} {s[1:4]} {s[4:6]}"),
    ('standard_4w', 7, [0, 1, 2], [3, 4, 5, 6], lambda s: f"{s[0:3]} {s[3:7]}"),
    ('legacy_4w', 6, [0, 1, 2], [3, 4, 5], lambda s: f"{s[0:3]} {s[3:6]}"),
    ('motorcycle_7', 7, [0, 1], [2, 3, 4, 5, 6], lambda s: f"{s[0:2]} {s[2:7]}"),
    ('motorcycle_6', 6, [0, 1], [2, 3, 4, 5], lambda s: f"{s[0:2]} {s[2:6]}"),
    ('motorcycle_alt', 6, [3, 4, 5], [0, 1, 2], lambda s: f"{s[0:3]} {s[3:6]}"),
    ('numeric_suffix_6', 6, [5], [0, 1, 2, 3, 4], lambda s: s),
    ('numeric_prefix_6', 6, [4, 5], [0, 1, 2, 3], lambda s: f"{s[:4]} {s[4:]}"),
    ('numeric_identifier_11', 11, [], list(range(11)), lambda s: f"{s[:4]}-{s[4:]}"),
]


@dataclass
class QualityMetrics:
    sharpness: float
    brightness: float
    contrast: float
    width: int
    height: int
    aspect_ratio: float
    is_usable: bool
    quality_score: float
    quality_flags: List[str] = field(default_factory=list)


@dataclass
class CharacterPositionAnalysis:
    index: int
    expected_type: str  # "LETTER", "DIGIT", or "ANY"
    candidates: Dict[str, str]  # model_name -> char
    agreed_char: str
    is_ambiguous: bool
    confidence: float
    resolution_rule: str


@dataclass
class QuadEnsembleResult:
    decision: str  # "ACCEPT" or "NEEDS_REVIEW"
    final_plate: str
    display_plate: str
    confidence: float
    method: str  # "MAJORITY_4_OF_4", "MAJORITY_3_OF_4", "MAJORITY_2_OF_4", "TIE_RESOLVED_2_VS_2", "CHARACTER_CONSENSUS", "REGISTERED_MATCH", "SINGLE_MODEL"
    matched_format: Optional[str]
    model_predictions: Dict[str, Dict[str, Any]]
    char_analysis: List[CharacterPositionAnalysis]
    quality: QualityMetrics
    review_reason: Optional[str] = None


# Backward-compatible alias
TripleEnsembleResult = QuadEnsembleResult


def assess_image_quality(bgr_crop: np.ndarray) -> QualityMetrics:
    """Evaluate plate crop quality (sharpness, lighting, contrast, dimensions)."""
    if bgr_crop is None or bgr_crop.size == 0:
        return QualityMetrics(
            sharpness=0.0, brightness=0.0, contrast=0.0,
            width=0, height=0, aspect_ratio=0.0,
            is_usable=False, quality_score=0.0,
            quality_flags=["EMPTY_IMAGE"]
        )

    h, w = bgr_crop.shape[:2]
    gray = cv2.cvtColor(bgr_crop, cv2.COLOR_BGR2GRAY) if len(bgr_crop.shape) == 3 else bgr_crop

    # Laplacian variance for focus / blur detection (standardized to 64px height so high-res crops are not penalized)
    lap = cv2.Laplacian(gray, cv2.CV_64F)
    sharpness = float(lap.var())
    if h > 64:
        norm_w = max(16, int(round(w * (64.0 / float(h)))))
        gray_norm = cv2.resize(gray, (norm_w, 64), interpolation=cv2.INTER_AREA)
        sharpness = max(sharpness, float(cv2.Laplacian(gray_norm, cv2.CV_64F).var()))

    # Pixel brightness & contrast
    brightness = float(np.mean(gray))
    contrast = float(np.std(gray))
    aspect_ratio = float(w) / float(max(1, h))

    flags = []
    if sharpness < 25.0:
        flags.append("BLURRY")
    if brightness < 30.0:
        flags.append("UNDEREXPOSED")
    elif brightness > 235.0:
        flags.append("OVEREXPOSED")
    if contrast < 20.0:
        flags.append("LOW_CONTRAST")
    if h < 16 or w < 45:
        flags.append("TOO_SMALL")

    is_usable = ("TOO_SMALL" not in flags) and (sharpness >= 18.0)
    score = min(1.0, max(0.0, (sharpness / 200.0) * 0.4 + (contrast / 80.0) * 0.3 + (1.0 - abs(brightness - 128) / 128.0) * 0.3))

    return QualityMetrics(
        sharpness=sharpness,
        brightness=brightness,
        contrast=contrast,
        width=w,
        height=h,
        aspect_ratio=aspect_ratio,
        is_usable=is_usable,
        quality_score=score,
        quality_flags=flags
    )


def score_and_fix_syntax(candidate: str, template_spec: Tuple) -> Tuple[Optional[str], int, str]:
    """Match a template without substituting any OCR characters."""
    name, length, let_indices, dig_indices, formatter = template_spec
    if len(candidate) != length:
        return None, 999, ""

    if any(not candidate[idx].isalpha() for idx in let_indices):
        return None, 999, ""
    if any(not candidate[idx].isdigit() for idx in dig_indices):
        return None, 999, ""
    return candidate, 0, formatter(candidate)


def extract_best_template_match(raw_string: str, registered_plates: Optional[Dict[str, Any]] = None) -> Tuple[str, str, float, str, int, bool]:
    """Preserve short plates and numeric identifiers up to the database's 20 digits."""
    if not raw_string:
        return "", "", 0.0, "", 999, False

    regs = registered_plates or {}
    clean = re.sub(r'[^A-Z0-9]', '', raw_string.upper())
    if clean in NON_PLATE_WORDS:
        return "", "", 0.0, "", 999, False
    if not (5 <= len(clean) <= 7 or (clean.isdigit() and 8 <= len(clean) <= 20)):
        tokens = re.findall(r'[A-Z0-9]+', raw_string.upper())
        headers = sorted((i for i, token in enumerate(tokens) if token in NON_PLATE_WORDS),
                         key=lambda i: len(tokens[i]), reverse=True)
        for index in headers:
            if len(clean) <= 7 or (clean.isdigit() and len(clean) <= 20):
                break
            tokens[index] = ''
            clean = ''.join(tokens)

    if len(clean) < 4 or clean in NON_PLATE_WORDS:
        return "", "", 0.0, "", 999, False

    if 5 <= len(clean) <= 7 or (clean.isdigit() and 8 <= len(clean) <= 20):
        if len(set(clean)) <= 1:
            return "", "", 0.0, "", 999, False
        for template in PH_TEMPLATES:
            exact, _, display = score_and_fix_syntax(clean, template)
            if exact:
                return clean, display, 1.0, template[0], 0, clean in regs
        kind = "numeric_identifier" if len(clean) > 7 else "alphanumeric"
        return clean, clean, 1.0, kind, 0, clean in regs

    if clean.isdigit():
        return "", "", 0.0, "", 999, False

    # Longer OCR strings can include headers or border noise. Extract only
    # exact known-format windows; never invent characters to make one fit.
    candidates = []
    for L in (6, 7, 11):
        for start in range(len(clean) - L + 1):
            sub = clean[start:start + L]
            for tpl in PH_TEMPLATES:
                fixed, cost, disp = score_and_fix_syntax(sub, tpl)
                if fixed:
                    is_reg = fixed in regs
                    priority = cost  # Registration classifies a plate; it must not choose its OCR text.
                    candidates.append((priority, cost, -len(sub), fixed, disp, tpl[0], is_reg))

    if candidates:
        candidates.sort(key=lambda x: (x[0], x[1], x[2]))
        best = candidates[0]
        conf = max(0.5, 1.0 - (best[1] * 0.15))
        return best[3], best[4], conf, best[5], best[1], best[6]

    return "", "", 0.0, "", 999, False


def align_and_resolve_characters(
    model_predictions: Dict[str, Tuple[str, float]],
    target_length: int,
    template_name: Optional[str] = None
) -> Tuple[str, List[CharacterPositionAnalysis]]:
    """
    Perform character-level comparison across models (up to 4 readers).
    Resolves discrepancies using positional Philippine plate syntax rules.
    """
    # Select template rules
    matching_tpl = None
    if template_name:
        for tpl in PH_TEMPLATES:
            if tpl[0] == template_name:
                matching_tpl = tpl
                break
    if not matching_tpl:
        for tpl in PH_TEMPLATES:
            if tpl[1] == target_length:
                matching_tpl = tpl
                break

    letter_indices = matching_tpl[2] if matching_tpl else []
    digit_indices = matching_tpl[3] if matching_tpl else []

    aligned_chars = []
    char_analyses = []

    for idx in range(target_length):
        expected = "LETTER" if idx in letter_indices else ("DIGIT" if idx in digit_indices else "ANY")
        char_votes: Dict[str, int] = {}
        model_chars: Dict[str, str] = {}
        char_weights: Dict[str, float] = {}

        for m_name, (m_text, m_conf) in model_predictions.items():
            if not m_text:
                continue
            # If model text is target length, take char at idx
            if len(m_text) == target_length:
                c = m_text[idx]
            else:
                # Sliding alignment fallback
                c = m_text[min(idx, len(m_text) - 1)]

            model_chars[m_name] = c
            char_votes[c] = char_votes.get(c, 0) + 1
            char_weights[c] = char_weights.get(c, 0.0) + max(0.1, m_conf)

        if not model_chars:
            aligned_chars.append("?")
            continue

        # Check for ambiguity
        unique_chars = list(model_chars.values())
        is_ambiguous = len(set(unique_chars)) > 1

        rule_used = "UNANIMOUS"
        chosen_char = unique_chars[0]

        if is_ambiguous:
            # 1. Positional syntax enforcement
            syntax_compliant = []
            for c in unique_chars:
                if expected == "LETTER" and c.isalpha():
                    syntax_compliant.append(c)
                elif expected == "DIGIT" and c.isdigit():
                    syntax_compliant.append(c)

            if syntax_compliant:
                # Pick the most voted syntax-compliant char
                chosen_char = max(syntax_compliant, key=lambda c: (char_votes.get(c, 0), char_weights.get(c, 0.0)))
                rule_used = f"POSITIONAL_SYNTAX_{expected}"
            else:
                # Try confusable mapping
                converted = []
                for c in unique_chars:
                    if expected == "LETTER" and c in DIGIT_TO_LETTER:
                        converted.append(DIGIT_TO_LETTER[c])
                    elif expected == "DIGIT" and c in LETTER_TO_DIGIT:
                        converted.append(LETTER_TO_DIGIT[c])

                if converted:
                    chosen_char = max(converted, key=lambda c: char_votes.get(c, 0))
                    rule_used = f"CONFUSABLE_MAPPING_TO_{expected}"
                else:
                    # Fallback to weighted vote
                    chosen_char = max(char_weights.keys(), key=lambda c: char_weights[c])
                    rule_used = "WEIGHTED_CONFIDENCE"
        else:
            # If all models agree on this char, apply positional fix if needed (e.g. all saw 0 instead of D)
            if expected == "LETTER" and chosen_char in DIGIT_TO_LETTER:
                chosen_char = DIGIT_TO_LETTER[chosen_char]
                rule_used = "UNANIMOUS_WITH_POSITIONAL_FIX"
            elif expected == "DIGIT" and chosen_char in LETTER_TO_DIGIT:
                chosen_char = LETTER_TO_DIGIT[chosen_char]
                rule_used = "UNANIMOUS_WITH_POSITIONAL_FIX"

        aligned_chars.append(chosen_char)
        char_analyses.append(CharacterPositionAnalysis(
            index=idx,
            expected_type=expected,
            candidates=model_chars,
            agreed_char=chosen_char,
            is_ambiguous=is_ambiguous,
            confidence=max(char_weights.values()) / max(0.1, sum(char_weights.values())),
            resolution_rule=rule_used
        ))

    composite_plate = "".join(aligned_chars)
    return composite_plate, char_analyses


class QuadReaderEnsemble:
    """
    Unified Quad-Reader Pipeline orchestrating:
    1. EasyOCR
    2. PaddleOCR
    3. LPRNet
    4. GPT Vision (GPT-4o-mini / GPT-4o)

    Executes readers on cropped plate frames and arbitrates using majority voting,
    character alignment, and image quality checks.
    """

    def __init__(
        self,
        easyocr_reader=None,
        paddleocr_reader=None,
        lprnet_recognizer=None,
        gpt_vision_reader: Optional[GPTVisionReader] = None,
        min_accept_confidence: float = 0.50,
        min_sharpness: float = 8.0,
        reader_pool: Optional[BoundedReaderPool] = None
    ):
        self.easyocr = easyocr_reader
        self.paddleocr = paddleocr_reader
        self.lprnet = lprnet_recognizer
        self.gpt_vision = gpt_vision_reader or GPTVisionReader()
        self.min_accept_confidence = min_accept_confidence
        self.min_sharpness = min_sharpness
        self.reader_pool = reader_pool or BoundedReaderPool()
        self.gpu_extractor = GPUCharacterExtractor()
        if self.easyocr is not None and hasattr(self.easyocr, "recognizer"):
            self.gpu_extractor.accelerate_easyocr_reader(self.easyocr)

    @staticmethod
    def preprocess_crop(bgr_crop: np.ndarray, target_h=None) -> Tuple[np.ndarray, np.ndarray]:
        """Standardize crop height using cubic interpolation + gamma/CLAHE enhancement."""
        if bgr_crop is None or bgr_crop.size == 0:
            return bgr_crop, bgr_crop

        crop_h, crop_w = bgr_crop.shape[:2]
        if target_h is None:
            target_h = 96
        scale = target_h / float(max(1, crop_h))
        target_w = max(10, int(crop_w * scale))
        resized = cv2.resize(bgr_crop, (target_w, target_h), interpolation=cv2.INTER_CUBIC)

        gray = cv2.cvtColor(resized, cv2.COLOR_BGR2GRAY) if resized.ndim == 3 else resized
        mean_val = float(np.mean(gray))
        if mean_val < 70.0:
            lut = np.array([((i / 255.0) ** (1.0 / 1.40)) * 255.0 for i in range(256)]).clip(0, 255).astype(np.uint8)
            gray = cv2.LUT(gray, lut)
        elif mean_val > 215.0:
            lut = np.array([((i / 255.0) ** (1.0 / 0.75)) * 255.0 for i in range(256)]).clip(0, 255).astype(np.uint8)
            gray = cv2.LUT(gray, lut)

        clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
        enhanced = clahe.apply(gray)
        gaussian = cv2.GaussianBlur(enhanced, (0, 0), 1.5)
        sharpened = cv2.addWeighted(enhanced, 1.4, gaussian, -0.4, 0)
        sharpened_bgr = cv2.cvtColor(sharpened, cv2.COLOR_GRAY2BGR)

        return resized, sharpened_bgr

    @staticmethod
    def number_region(crop):
        """Rectify a visible character row; never discard a fixed image fraction."""
        h, w = crop.shape[:2]
        if h < 16 or w < 45:
            return crop
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if crop.ndim == 3 else crop
        _, mask = cv2.threshold(cv2.GaussianBlur(gray, (3, 3), 0), 0, 255,
                                cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        boxes = [cv2.boundingRect(contour) for contour in contours]
        boxes = [box for box in boxes
                 if .2 * h < box[3] < .85 * h and .015 * w < box[2] < .25 * w
                 and box[0] > 0 and box[1] > 0
                 and box[0] + box[2] < w and box[1] + box[3] < h]
        if len(boxes) < 5:
            return crop
        xs = np.array([x + bw / 2 for x, y, bw, bh in boxes])
        if np.ptp(xs) < .35 * w:
            return crop
        top_values = np.array([y for x, y, bw, bh in boxes])
        bottom_values = np.array([y + bh for x, y, bw, bh in boxes])
        tops = np.polyfit(xs, top_values, 1)
        bottoms = np.polyfit(xs, bottom_values, 1)
        median_height = float(np.median([box[3] for box in boxes]))
        # A single misaligned graphic must not become a digit when context is cropped away.
        if (max(abs(tops[0]), abs(bottoms[0])) > .65
                or np.median(np.abs(np.polyval(tops, xs) - top_values)) > .25 * median_height
                or np.median(np.abs(np.polyval(bottoms, xs) - bottom_values)) > .25 * median_height
                or np.max(np.abs(np.polyval(tops, xs) - top_values)) > .35 * median_height
                or np.max(np.abs(np.polyval(bottoms, xs) - bottom_values)) > .35 * median_height):
            return crop
        pad = .1 * median_height
        # Include edge characters that touch the holder and lack a separate contour.
        margin = 2 * float(np.median([box[2] for box in boxes]))
        left = max(0, min(box[0] for box in boxes) - margin)
        right = min(w - 1, max(box[0] + box[2] for box in boxes) + margin)
        top_left = np.clip(np.polyval(tops, left) - pad, 0, h - 1)
        top_right = np.clip(np.polyval(tops, right) - pad, 0, h - 1)
        bottom_left = np.clip(np.polyval(bottoms, left) + pad, 0, h - 1)
        bottom_right = np.clip(np.polyval(bottoms, right) + pad, 0, h - 1)
        out_w = round(right - left)
        out_h = round(max(bottom_left - top_left, bottom_right - top_right))
        if out_w < 45 or out_h < 16 or min(bottom_left - top_left, bottom_right - top_right) < 16:
            return crop
        source = np.array([[left, top_left], [right, top_right],
                           [right, bottom_right], [left, bottom_left]], np.float32)
        target = np.array([[0, 0], [out_w - 1, 0], [out_w - 1, out_h - 1],
                           [0, out_h - 1]], np.float32)
        matrix = cv2.getPerspectiveTransform(source, target)
        return cv2.warpPerspective(crop, matrix, (out_w, out_h),
                                   flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)

    @staticmethod
    def _is_known_ph_template(raw_text: str) -> bool:
        plate, _, _, fmt, _, _ = extract_best_template_match(raw_text, None)
        return bool(plate and fmt in {tpl[0] for tpl in PH_TEMPLATES})

    @staticmethod
    def _trim_side_borders(crop: np.ndarray, ratio: float = 0.035) -> np.ndarray:
        """Trim narrow left/right plastic plate-holder edges without touching central glyphs."""
        if crop is None or crop.size == 0:
            return crop
        h, w = crop.shape[:2]
        pad_x = int(round(w * ratio))
        if w - 2 * pad_x < 45:
            return crop
        trimmed = PlatePreprocessor.trim_holder_borders(crop) if crop.ndim == 3 else crop
        return trimmed[:, pad_x:w - pad_x]

    @staticmethod
    def _parse_easyocr_items(res: Any) -> Tuple[str, float]:
        if not res:
            return "", 0.0
        blocks = []
        for item in res:
            if not item or len(item) < 2:
                continue
            txt = str(item[1]).strip()
            if not txt:
                continue
            conf = float(item[2]) if len(item) > 2 else 0.5
            pts = item[0] if isinstance(item[0], (list, tuple)) and len(item[0]) > 0 else []
            if pts and isinstance(pts[0], (list, tuple)):
                xs = [float(p[0]) for p in pts]
                ys = [float(p[1]) for p in pts]
                min_x, min_y = min(xs), min(ys)
                bh = max(1.0, max(ys) - min_y)
            else:
                min_x, min_y, bh = 0.0, 0.0, 1.0
            blocks.append((min_x, min_y, bh, txt, conf))

        if not blocks:
            return "", 0.0

        if len(blocks) > 1:
            max_bh = max(b[2] for b in blocks)
            filtered = [b for b in blocks if b[2] >= 0.40 * max_bh and b[3].upper() not in NON_PLATE_WORDS]
            if filtered:
                blocks = filtered

        blocks.sort(key=lambda b: b[0])
        tokens = [b[3] for b in blocks]
        confs = [b[4] for b in blocks]
        return " ".join(tokens), sum(confs) / max(1, len(confs))

    def read_with_easyocr(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run GTX GPU character-band localization + multi-view tensor enhancement + beamsearch CTC."""
        if self.easyocr is None:
            return "", 0.0

        try:
            gpu_ext = getattr(self, "gpu_extractor", None)
            if gpu_ext is not None and hasattr(self.easyocr, "recognizer") and not getattr(self.easyocr, "gtx_accelerated", False):
                gpu_ext.accelerate_easyocr_reader(self.easyocr)

            gpu_active = bool(
                (gpu_ext is not None and gpu_ext.is_gpu_available)
                or getattr(self.easyocr, "gtx_accelerated", False)
                or str(getattr(self.easyocr, "device", "cpu")) != "cpu"
            )
            allowlist = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-"
            decoder = "beamsearch" if gpu_active else "greedy"
            beam_w = 5 if gpu_active else 3

            def recognize(image):
                gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
                height, width = gray.shape[:2]
                return self.easyocr.recognize(
                    gray, horizontal_list=[[0, width, 0, height]], free_list=[],
                    allowlist=allowlist, paragraph=False,
                    decoder=decoder, beamWidth=beam_w, detail=1)

            def parse_items(res):
                if hasattr(self, "_parse_easyocr_items"):
                    return self._parse_easyocr_items(res)
                if not res:
                    return "", 0.0
                tokens = [str(it[1]).strip() for it in res if it and len(it) > 1 and str(it[1]).strip()]
                confs = [float(it[2]) if len(it) > 2 else 0.5 for it in res if it and len(it) > 1 and str(it[1]).strip()]
                return (" ".join(tokens), sum(confs) / len(confs)) if tokens else ("", 0.0)

            def check_tpl(text):
                if hasattr(self, "_is_known_ph_template"):
                    return self._is_known_ph_template(text)
                return bool(text and len(text.strip()) >= 5)

            candidates = []

            def add_candidate(text, conf):
                if not text:
                    return False
                is_tpl = check_tpl(text)
                candidates.append((is_tpl, conf, text))
                return is_tpl and conf >= 0.85

            # 1. GTX GPU Character-Band Isolation (CRAFT on GTX) + Multi-View Padded Canvas Enhancement
            if gpu_active and gpu_ext is not None:
                band_crop = gpu_ext.localize_character_band(self.easyocr, bgr_crop)
                base_for_gpu = band_crop if band_crop is not None else self.number_region(bgr_crop)
                gpu_views = gpu_ext.enhance_variants_gpu(base_for_gpu)

                for view_gray in gpu_views:
                    txt, cf = parse_items(recognize(view_gray))
                    if add_candidate(txt, cf):
                        return txt, cf

            # 2. Standard number-region fallback when GPU views are absent or low-confidence
            best_so_far = max((c[1] for c in candidates), default=0.0)
            if best_so_far < 0.72:
                num_crop = self.number_region(bgr_crop)
                resized, sharpened_bgr = self.preprocess_crop(num_crop)
                sharpened_gray = cv2.cvtColor(sharpened_bgr, cv2.COLOR_BGR2GRAY)

                for line_img in (sharpened_gray, resized):
                    txt, cf = parse_items(recognize(line_img))
                    if add_candidate(txt, cf):
                        return txt, cf

            if not candidates:
                return "", 0.0

            # Multi-view character consensus when multiple GPU views agree on the same plate string
            vote_counts = {}
            for is_tpl, cf, txt in candidates:
                clean_key = "".join(ch for ch in txt.upper() if ch.isalnum())
                if clean_key:
                    vote_counts.setdefault(clean_key, []).append(cf + (0.05 if is_tpl else 0.0))

            best_multi = max(
                ((len(scores), sum(scores) / len(scores), key) for key, scores in vote_counts.items()),
                default=(0, 0.0, ""),
            )
            if best_multi[0] >= 2 and check_tpl(best_multi[2]):
                return best_multi[2], min(0.99, best_multi[1])

            candidates.sort(key=lambda item: (item[0], item[1]), reverse=True)
            _, best_conf, best_text = candidates[0]
            return best_text, best_conf
        except Exception:
            return "", 0.0

    def _decode_paddle_output(self, res: Any) -> Tuple[str, float]:
        if not res:
            return "", 0.0

        tokens = []
        confs = []

        # PaddleOCR 3 / LocalPPOCRv6Recognizer returns dictionaries with rec_text(s)/rec_score(s).
        result_items = res if isinstance(res, (list, tuple)) else [res]
        for item in result_items:
            if isinstance(item, dict):
                data = item.get('res', item)
                texts = data.get('rec_texts', [])
                scores = data.get('rec_scores', [])
                if 'rec_text' in data:
                    texts = [data['rec_text']]
                    scores = [data.get('rec_score', 0.0)]
                for index, text in enumerate(texts):
                    if str(text).strip():
                        tokens.append(str(text).strip())
                        confs.append(float(scores[index]) if index < len(scores) else 0.0)
        if tokens:
            return ' '.join(tokens), sum(confs) / len(confs)

        lines = res[0] if isinstance(res, list) and len(res) > 0 and isinstance(res[0], list) else res
        for line in lines:
            if isinstance(line, (list, tuple)) and len(line) > 1 and line[1]:
                txt = str(line[1][0]).strip() if isinstance(line[1], (list, tuple)) else str(line[1]).strip()
                conf = float(line[1][1]) if isinstance(line[1], (list, tuple)) and len(line[1]) > 1 else 0.5
                tokens.append(txt)
                confs.append(conf)
            elif hasattr(line, 'rec_text'):
                tokens.append(str(line.rec_text).strip())
                confs.append(getattr(line, 'rec_score', 0.5))

        if not tokens:
            return "", 0.0

        avg_conf = sum(confs) / max(1, len(confs)) if confs else 0.5
        return " ".join(tokens), avg_conf

    def read_with_paddleocr(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run PaddleOCR on standardized crop with multi-variant fallback when holder edges intrude."""
        if self.paddleocr is None:
            return "", 0.0

        try:
            resized, sharpened_bgr = self.preprocess_crop(bgr_crop)

            if getattr(self.paddleocr, 'plate_recognition_only', False):
                primary_crop = self.number_region(bgr_crop)
                txt, conf = self._decode_paddle_output(self.paddleocr.predict(primary_crop))
                if txt and self._is_known_ph_template(txt) and conf >= 0.88:
                    return txt, conf

                candidates: List[Tuple[bool, float, str]] = []
                if txt:
                    candidates.append((self._is_known_ph_template(txt), conf, txt))

                trimmed_crop = self._trim_side_borders(primary_crop, ratio=0.04)
                gpu_paddle_crop = self.gpu_extractor.enhance_for_paddle_bgr(trimmed_crop)
                for alt_crop in (trimmed_crop, gpu_paddle_crop, sharpened_bgr):
                    alt_txt, alt_conf = self._decode_paddle_output(self.paddleocr.predict(alt_crop))
                    if alt_txt:
                        is_tpl = self._is_known_ph_template(alt_txt)
                        candidates.append((is_tpl, alt_conf, alt_txt))
                        if is_tpl and alt_conf >= 0.90:
                            break

                if not candidates:
                    return "", 0.0
                candidates.sort(key=lambda item: (item[0], item[1]), reverse=True)
                _, best_conf, best_txt = candidates[0]
                return best_txt, best_conf

            res = None
            try:
                res = self.paddleocr.ocr(sharpened_bgr)
            except Exception:
                try:
                    res = self.paddleocr.predict(sharpened_bgr)
                except Exception:
                    pass

            return self._decode_paddle_output(res)
        except Exception:
            return "", 0.0

    def read_with_lprnet(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run LPRNet on plate crop."""
        if self.lprnet is None or getattr(self.lprnet, 'meta', {}).get('status') == 'uninitialized':
            return "", 0.0

        try:
            out = self.lprnet.recognize(bgr_crop)
            if out and out.confirmed_plate:
                return out.confirmed_plate, float(out.confidence)
            elif out and out.raw_text:
                return out.raw_text, float(out.confidence)
            return "", 0.0
        except Exception:
            return "", 0.0

    def read_with_gpt_vision(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run GPT Vision (GPT-4o-mini) on plate crop if configured."""
        if self.gpt_vision is None or not self.gpt_vision.is_configured():
            return "", 0.0

        try:
            return self.gpt_vision.recognize(bgr_crop)
        except Exception:
            return "", 0.0

    def recognize_ensemble(
        self,
        bgr_crop: np.ndarray,
        registered_plates: Optional[Dict[str, Any]] = None
    ) -> QuadEnsembleResult:
        """
        Full 4-reader recognition pass with majority voting, character comparison,
        and accept/review decision.
        """
        quality = assess_image_quality(bgr_crop)

        # Gate 1: Check extreme blur / image failure
        if not quality.is_usable or quality.sharpness < self.min_sharpness:
            return QuadEnsembleResult(
                decision="NEEDS_REVIEW",
                final_plate="",
                display_plate="",
                confidence=0.0,
                method="QUALITY_GATE_REJECTION",
                matched_format=None,
                model_predictions={},
                char_analysis=[],
                quality=quality,
                review_reason=f"Plate image quality insufficient (sharpness={quality.sharpness:.1f}, flags={','.join(quality.quality_flags)})"
            )

        readers = {}
        if self.easyocr:
            readers['easyocr'] = self.read_with_easyocr
        if self.paddleocr:
            readers['paddleocr'] = self.read_with_paddleocr
        if self.lprnet:
            readers['lprnet'] = self.read_with_lprnet
        if len(readers) < 2 and self.gpt_vision and self.gpt_vision.is_configured():
            readers['gpt_vision'] = self.read_with_gpt_vision
        predictions = self.reader_pool.read(readers, bgr_crop, timeout=4.0)
        easy_raw, easy_conf = predictions.get('easyocr', ('', 0.0))
        pad_raw, pad_conf = predictions.get('paddleocr', ('', 0.0))
        lpr_raw, lpr_conf = predictions.get('lprnet', ('', 0.0))
        gpt_raw, gpt_conf = predictions.get('gpt_vision', ('', 0.0))

        # Parse & extract candidate plates using Philippine templates
        easy_plate, easy_disp, easy_score, easy_fmt, _, easy_reg = extract_best_template_match(easy_raw, registered_plates)
        pad_plate, pad_disp, pad_score, pad_fmt, _, pad_reg = extract_best_template_match(pad_raw, registered_plates)
        lpr_plate, lpr_disp, lpr_score, lpr_fmt, _, lpr_reg = extract_best_template_match(lpr_raw, registered_plates)
        gpt_plate, gpt_disp, gpt_score, gpt_fmt, _, gpt_reg = extract_best_template_match(gpt_raw, registered_plates)

        model_preds = {
            "easyocr": {"raw": easy_raw, "plate": easy_plate, "display": easy_disp, "conf": easy_conf, "format": easy_fmt, "is_reg": easy_reg},
            "paddleocr": {"raw": pad_raw, "plate": pad_plate, "display": pad_disp, "conf": pad_conf, "format": pad_fmt, "is_reg": pad_reg},
            "lprnet": {"raw": lpr_raw, "plate": lpr_plate, "display": lpr_disp, "conf": lpr_conf, "format": lpr_fmt, "is_reg": lpr_reg},
            "gpt_vision": {"raw": gpt_raw, "plate": gpt_plate, "display": gpt_disp, "conf": gpt_conf, "format": gpt_fmt, "is_reg": gpt_reg},
        }

        active_candidates: Dict[str, Tuple[str, float]] = {}
        if easy_plate:
            active_candidates["easyocr"] = (easy_plate, easy_conf)
        if pad_plate:
            active_candidates["paddleocr"] = (pad_plate, pad_conf)
        if lpr_plate:
            active_candidates["lprnet"] = (lpr_plate, lpr_conf)
        if gpt_plate:
            active_candidates["gpt_vision"] = (gpt_plate, gpt_conf)

        num_active = len(active_candidates)

        # If zero readers found anything
        if not active_candidates:
            return QuadEnsembleResult(
                decision="NEEDS_REVIEW",
                final_plate="",
                display_plate="",
                confidence=0.0,
                method="NO_READINGS",
                matched_format=None,
                model_predictions=model_preds,
                char_analysis=[],
                quality=quality,
                review_reason="None of the active OCR engines detected legible characters"
            )

        # Require independently agreeing, sufficiently confident readers for automatic logging.
        reliable = {name: value for name, value in active_candidates.items()
                    if value[1] >= self.min_accept_confidence}
        votes = {}
        for name, (plate, confidence) in reliable.items():
            votes.setdefault(plate, []).append(name)
        best_support = max((len(names) for names in votes.values()), default=0)
        leaders = [plate for plate, names in votes.items() if len(names) == best_support]
        if best_support < 2 and len(reliable) >= 2:
            glyphs = str.maketrans({'O':'0', 'D':'0', 'Q':'0', 'I':'1', 'L':'1', 'Z':'2', 'S':'5', 'G':'6', 'B':'8'})
            families = {}
            for name, (plate, confidence) in reliable.items():
                key = (len(plate), plate.translate(glyphs))
                families.setdefault(key, []).append(name)
            supported = [names for names in families.values() if len(names) >= 2]
            if len(supported) == 1:
                names = sorted(supported[0], key=lambda name: reliable[name][1], reverse=True)
                if reliable[names[0]][1] - reliable[names[1]][1] >= 0.02:
                    name = names[0]
                    plate, confidence = reliable[name]
                    return QuadEnsembleResult(
                        decision='ACCEPT', final_plate=plate, display_plate=model_preds[name]['display'],
                        confidence=sum(reliable[n][1] for n in names)/len(names),
                        method='INDEPENDENT_CONFUSABLE_AGREEMENT', matched_format=model_preds[name]['format'],
                        model_predictions=model_preds, char_analysis=[], quality=quality, review_reason=None)
        # One trained recognizer can nominate a very strong reading; the live tracker
        # logs clear 80%+ readings immediately and confirms lower scores across frames.
        if len(reliable) == 1 and 'paddleocr' in reliable and reliable['paddleocr'][1] >= 0.80:
            plate, confidence = reliable['paddleocr']
            return QuadEnsembleResult(
                decision='ACCEPT', final_plate=plate, display_plate=model_preds['paddleocr']['display'],
                confidence=confidence, method='STRONG_PADDLE_TEMPORAL_CANDIDATE',
                matched_format=model_preds['paddleocr']['format'], model_predictions=model_preds,
                char_analysis=[], quality=quality, review_reason=None)
        if best_support < 2 or len(leaders) != 1:
            name, (plate, confidence) = max(active_candidates.items(), key=lambda item: item[1][1])
            return QuadEnsembleResult(
                decision='NEEDS_REVIEW', final_plate=plate,
                display_plate=model_preds[name].get('display') or plate,
                confidence=confidence, method='INSUFFICIENT_READER_AGREEMENT',
                matched_format=model_preds[name].get('format'), model_predictions=model_preds,
                char_analysis=[], quality=quality,
                review_reason='At least two reliable readers must agree before automatic logging')
        active_candidates = reliable
        num_active = len(active_candidates)

        # Arbitration Step 1: Majority Voting (String-Level)
        plate_votes: Dict[str, List[str]] = {}
        for m_name, (plate, conf) in active_candidates.items():
            plate_votes.setdefault(plate, []).append(m_name)

        # Check 4/4 unanimous agreement
        for plate, supporting_models in plate_votes.items():
            if len(supporting_models) >= 4:
                avg_c = sum(active_candidates[m][1] for m in supporting_models) / len(supporting_models)
                disp = next(model_preds[m]["display"] for m in supporting_models if model_preds[m].get("display"))
                fmt = next(model_preds[m]["format"] for m in supporting_models if model_preds[m].get("format"))
                return QuadEnsembleResult(
                    decision="ACCEPT",
                    final_plate=plate,
                    display_plate=disp,
                    confidence=max(0.98, avg_c),
                    method="MAJORITY_4_OF_4",
                    matched_format=fmt,
                    model_predictions=model_preds,
                    char_analysis=[],
                    quality=quality,
                    review_reason=None
                )

        # Check 3-model majority agreement (3 of 4 or 3 of 3)
        for plate, supporting_models in plate_votes.items():
            if len(supporting_models) >= 3:
                avg_c = sum(active_candidates[m][1] for m in supporting_models) / len(supporting_models)
                disp = next(model_preds[m]["display"] for m in supporting_models if model_preds[m].get("display"))
                fmt = next(model_preds[m]["format"] for m in supporting_models if model_preds[m].get("format"))
                method_name = "MAJORITY_3_OF_4" if num_active >= 4 else "MAJORITY_3_OF_3"
                return QuadEnsembleResult(
                    decision="ACCEPT",
                    final_plate=plate,
                    display_plate=disp,
                    confidence=max(0.90, avg_c),
                    method=method_name,
                    matched_format=fmt,
                    model_predictions=model_preds,
                    char_analysis=[],
                    quality=quality,
                    review_reason=None
                )

        # Check 2-model majority (2 of 3, or plurality 2 of 4 without tie)
        two_vote_plates = [plate for plate, models in plate_votes.items() if len(models) == 2]
        if len(two_vote_plates) == 1:
            plate = two_vote_plates[0]
            supporting_models = plate_votes[plate]
            avg_c = sum(active_candidates[m][1] for m in supporting_models) / len(supporting_models)
            disp = next(model_preds[m]["display"] for m in supporting_models if model_preds[m].get("display"))
            fmt = next(model_preds[m]["format"] for m in supporting_models if model_preds[m].get("format"))
            method_name = "MAJORITY_2_OF_4" if num_active >= 4 else "MAJORITY_2_OF_3"
            return QuadEnsembleResult(
                decision="ACCEPT",
                final_plate=plate,
                display_plate=disp,
                confidence=avg_c,
                method=method_name,
                matched_format=fmt,
                model_predictions=model_preds,
                char_analysis=[],
                quality=quality,
                review_reason=None
            )

        # Tie Breaker: 2 vs 2 (e.g. Model A+B say Plate1, Model C+D say Plate2)
        if len(two_vote_plates) == 2:
            plate_a, plate_b = two_vote_plates[0], two_vote_plates[1]
            models_a = plate_votes[plate_a]
            models_b = plate_votes[plate_b]
            conf_a = sum(active_candidates[m][1] for m in models_a) / 2.0
            conf_b = sum(active_candidates[m][1] for m in models_b) / 2.0

            # Favor GPT Vision if it was part of one pair (due to high semantic accuracy on confusing characters)
            if "gpt_vision" in models_a and "gpt_vision" not in models_b:
                conf_a += 0.15
            elif "gpt_vision" in models_b and "gpt_vision" not in models_a:
                conf_b += 0.15

            winning_plate = plate_a if conf_a >= conf_b else plate_b
            winning_models = models_a if conf_a >= conf_b else models_b
            disp = next(model_preds[m]["display"] for m in winning_models if model_preds[m].get("display"))
            fmt = next(model_preds[m]["format"] for m in winning_models if model_preds[m].get("format"))

            return QuadEnsembleResult(
                decision="ACCEPT",
                final_plate=winning_plate,
                display_plate=disp,
                confidence=max(conf_a, conf_b),
                method="TIE_RESOLVED_2_VS_2",
                matched_format=fmt,
                model_predictions=model_preds,
                char_analysis=[],
                quality=quality,
                review_reason=None
            )

        # Arbitration Step 2: Character-Level Positional Comparison & Alignment
        lengths = [len(p[0]) for p in active_candidates.values()]
        target_len = max(set(lengths), key=lengths.count)

        composite_plate, char_analysis = align_and_resolve_characters(
            active_candidates,
            target_length=target_len
        )

        # Score composite plate against Philippine templates
        fixed_plate, fixed_disp, fixed_conf, fixed_fmt, cost, is_reg = extract_best_template_match(
            composite_plate,
            registered_plates
        )

        if fixed_plate:
            ambiguous_count = sum(1 for a in char_analysis if a.is_ambiguous)
            calibrated_conf = max(0.60, 1.0 - (ambiguous_count * 0.08) - (cost * 0.10))

            return QuadEnsembleResult(
                decision="ACCEPT",
                final_plate=fixed_plate,
                display_plate=fixed_disp,
                confidence=calibrated_conf,
                method="CHARACTER_CONSENSUS",
                matched_format=fixed_fmt,
                model_predictions=model_preds,
                char_analysis=char_analysis,
                quality=quality,
                review_reason=None
            )

        # Single model validated plate fallback
        best_single = max(active_candidates.items(), key=lambda x: x[1][1])
        single_m_name, (single_plate, single_conf) = best_single
        single_disp = model_preds[single_m_name]["display"]
        single_fmt = model_preds[single_m_name]["format"]

        return QuadEnsembleResult(
            decision="ACCEPT",
            final_plate=single_plate,
            display_plate=single_disp,
            confidence=max(0.65, single_conf),
            method=f"{single_m_name.upper()}_ACCEPTED",
            matched_format=single_fmt,
            model_predictions=model_preds,
            char_analysis=[],
            quality=quality,
            review_reason=None
        )


# Backward-compatible alias for existing imports
TripleReaderEnsemble = QuadReaderEnsemble

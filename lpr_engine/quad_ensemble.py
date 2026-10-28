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
import concurrent.futures
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

    # Laplacian variance for focus / blur detection
    lap = cv2.Laplacian(gray, cv2.CV_64F)
    sharpness = float(lap.var())

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
    """Score candidate against a Philippine plate template with positional corrections."""
    name, length, let_indices, dig_indices, formatter = template_spec
    if len(candidate) != length:
        return None, 999, ""

    chars = list(candidate)
    cost = 0

    for idx in let_indices:
        c = chars[idx]
        if c.isdigit():
            if c in DIGIT_TO_LETTER:
                chars[idx] = DIGIT_TO_LETTER[c]
                cost += 1
            else:
                return None, 999, ""
        elif not c.isalpha():
            return None, 999, ""

    for idx in dig_indices:
        c = chars[idx]
        if c.isalpha():
            if c in LETTER_TO_DIGIT:
                chars[idx] = LETTER_TO_DIGIT[c]
                cost += 1
            else:
                return None, 999, ""
        elif not c.isdigit():
            return None, 999, ""

    fixed = "".join(chars)
    return fixed, cost, formatter(fixed)


def extract_best_template_match(raw_string: str, registered_plates: Optional[Dict[str, Any]] = None) -> Tuple[str, str, float, str, int, bool]:
    """Extract exact 6 or 7 character Philippine plate using sliding window."""
    if not raw_string:
        return "", "", 0.0, "", 999, False

    regs = registered_plates or {}
    clean = re.sub(r'[^A-Z0-9]', '', raw_string.upper())
    for word in NON_PLATE_WORDS:
        clean = clean.replace(word, '')

    if len(clean) < 4:
        return "", "", 0.0, "", 999, False

    candidates = []
    for L in (6, 7):
        for start in range(len(clean) - L + 1):
            sub = clean[start:start + L]
            for tpl in PH_TEMPLATES:
                fixed, cost, disp = score_and_fix_syntax(sub, tpl)
                if fixed:
                    is_reg = fixed in regs
                    priority = cost - (100 if is_reg else 0)
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
        min_sharpness: float = 8.0
    ):
        self.easyocr = easyocr_reader
        self.paddleocr = paddleocr_reader
        self.lprnet = lprnet_recognizer
        self.gpt_vision = gpt_vision_reader or GPTVisionReader()
        self.min_accept_confidence = min_accept_confidence
        self.min_sharpness = min_sharpness

    @staticmethod
    def preprocess_crop(bgr_crop: np.ndarray) -> Tuple[np.ndarray, np.ndarray]:
        """Standardize crop height to 70px using cubic interpolation + CLAHE enhancement."""
        if bgr_crop is None or bgr_crop.size == 0:
            return bgr_crop, bgr_crop

        crop_h, crop_w = bgr_crop.shape[:2]
        target_h = 70
        scale = target_h / float(max(1, crop_h))
        target_w = max(10, int(crop_w * scale))
        resized = cv2.resize(bgr_crop, (target_w, target_h), interpolation=cv2.INTER_CUBIC)

        gray = cv2.cvtColor(resized, cv2.COLOR_BGR2GRAY)
        clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
        enhanced = clahe.apply(gray)
        gaussian = cv2.GaussianBlur(enhanced, (0, 0), 1.5)
        sharpened = cv2.addWeighted(enhanced, 1.4, gaussian, -0.4, 0)
        sharpened_bgr = cv2.cvtColor(sharpened, cv2.COLOR_GRAY2BGR)

        return resized, sharpened_bgr

    def read_with_easyocr(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run EasyOCR on standardized CLAHE-enhanced & raw crop."""
        if self.easyocr is None:
            return "", 0.0

        try:
            resized, sharpened_bgr = self.preprocess_crop(bgr_crop)
            sharpened_gray = cv2.cvtColor(sharpened_bgr, cv2.COLOR_BGR2GRAY)

            # Pass 1: Enhanced sharpened crop
            res = self.easyocr.readtext(sharpened_gray, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
            if not res:
                # Pass 2: Resized raw crop
                res = self.easyocr.readtext(resized, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)

            if not res:
                return "", 0.0

            sorted_items = sorted(res, key=lambda x: min(pt[0] for pt in x[0]) if len(x) > 0 and len(x[0]) > 0 else 0)
            tokens = [str(item[1]).strip() for item in sorted_items if item[1]]
            confs = [float(item[2]) for item in sorted_items if len(item) > 2]
            avg_conf = sum(confs) / max(1, len(confs)) if confs else 0.5
            raw_text = " ".join(tokens)
            return raw_text, avg_conf
        except Exception:
            return "", 0.0

    def read_with_paddleocr(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run PaddleOCR on standardized crop with safe error handling."""
        if self.paddleocr is None:
            return "", 0.0

        try:
            resized, sharpened_bgr = self.preprocess_crop(bgr_crop)

            res = None
            try:
                res = self.paddleocr.ocr(sharpened_bgr)
            except Exception:
                try:
                    res = self.paddleocr.predict(sharpened_bgr)
                except Exception:
                    pass

            if not res:
                return "", 0.0

            tokens = []
            confs = []

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
        except Exception:
            return "", 0.0

    def read_with_lprnet(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """Run LPRNet on plate crop."""
        if self.lprnet is None:
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

        # Run active readers in parallel via ThreadPoolExecutor for low latency
        easy_raw, easy_conf = "", 0.0
        pad_raw, pad_conf = "", 0.0
        lpr_raw, lpr_conf = "", 0.0
        gpt_raw, gpt_conf = "", 0.0

        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
            fut_easy = executor.submit(self.read_with_easyocr, bgr_crop) if self.easyocr else None
            fut_pad = executor.submit(self.read_with_paddleocr, bgr_crop) if self.paddleocr else None
            fut_lpr = executor.submit(self.read_with_lprnet, bgr_crop) if self.lprnet else None
            fut_gpt = executor.submit(self.read_with_gpt_vision, bgr_crop) if (self.gpt_vision and self.gpt_vision.is_configured()) else None

            if fut_easy:
                try:
                    easy_raw, easy_conf = fut_easy.result(timeout=1.2)
                except Exception:
                    pass
            if fut_pad:
                try:
                    pad_raw, pad_conf = fut_pad.result(timeout=1.2)
                except Exception:
                    pass
            if fut_lpr:
                try:
                    lpr_raw, lpr_conf = fut_lpr.result(timeout=1.2)
                except Exception:
                    pass
            if fut_gpt:
                try:
                    gpt_raw, gpt_conf = fut_gpt.result(timeout=1.5)
                except Exception:
                    pass

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

        # Priority Check: Exact Registered Vehicle Match
        for m_name, (plate, conf) in active_candidates.items():
            if registered_plates and plate in registered_plates:
                fmt_name = model_preds[m_name].get("format") or "registered"
                disp_str = model_preds[m_name].get("display") or plate
                return QuadEnsembleResult(
                    decision="ACCEPT",
                    final_plate=plate,
                    display_plate=disp_str,
                    confidence=max(0.95, conf),
                    method="REGISTERED_MATCH",
                    matched_format=fmt_name,
                    model_predictions=model_preds,
                    char_analysis=[],
                    quality=quality,
                    review_reason=None
                )

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

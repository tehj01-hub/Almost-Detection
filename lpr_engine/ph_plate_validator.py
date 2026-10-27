"""
Philippine License Plate Format Validator & Ambiguity Decision Engine.

Rules implemented:
- LTO Standard 4-Wheel (2018+): AAA 1234
- LTO Legacy 4-Wheel (pre-2018): AAA 123
- LTO Conduction / Temporary Plates: A 123 BC, AB 1234, etc. (e.g. D566CP, N838RZ)
- LTO Motorcycle Plates: 123 ABC, AB 12345, MV Files
- Ambiguity routing: Never silently alters characters; returns AUTO_ACCEPT,
  NEEDS_HUMAN_REVIEW, or REJECT_NOISE.
"""

from dataclasses import dataclass, field
import re
from typing import Dict, List, Optional, Set, Tuple

from .alphabet import are_confusable_neighbors, normalize_plate_alnum
from .ctc_decoder import CandidatePrediction, DecodedPlateResult

NON_PLATE_WORDS = {
    "REGISTERED", "PHILIPPINES", "NCR", "IND", "MATATAG", "REPUBLIKA",
    "REGION", "DEALER", "LTO", "PILIPINAS", "METRO", "MANILA", "CALABARZON",
    "CENTRAL", "LUZON", "VISAYAS", "MINDANAO", "FOR", "REGISTRATION"
}


@dataclass
class ValidationDecision:
    action: str  # "AUTO_ACCEPT", "NEEDS_HUMAN_REVIEW", "REJECT_NOISE"
    confirmed_plate: str
    display_plate: str
    confidence: float
    matched_format: Optional[str] = None
    registered_match: Optional[Dict[str, object]] = None
    candidates: List[CandidatePrediction] = field(default_factory=list)
    review_reason: Optional[str] = None


class PhilippinePlateValidator:
    """
    Configurable validator for Philippine license plate patterns.
    """

    DEFAULT_PATTERNS = {
        "lto_standard_4w": (r"^[A-Z]{3}[0-9]{4}$", "Standard 4-Wheel (AAA 1234)"),
        "lto_legacy_4w": (r"^[A-Z]{3}[0-9]{3}$", "Legacy 4-Wheel (AAA 123)"),
        "lto_conduction": (r"^[A-Z]{1,2}[0-9]{3,4}[A-Z]{1,2}$", "Conduction / Temporary (A123BC / D566CP)"),
        "lto_motorcycle_1": (r"^[0-9]{3}[A-Z]{3}$", "Motorcycle (123 ABC)"),
        "lto_motorcycle_2": (r"^[A-Z]{2}[0-9]{4,5}$", "Motorcycle (AB 12345)"),
    }

    def __init__(
        self,
        enabled_patterns: Optional[List[str]] = None,
        min_auto_accept_conf: float = 0.75,
        min_auto_accept_agreement: int = 2,
    ) -> None:
        self.enabled_patterns = enabled_patterns or list(self.DEFAULT_PATTERNS.keys())
        self.min_auto_accept_conf = min_auto_accept_conf
        self.min_auto_accept_agreement = min_auto_accept_agreement

        self._compiled: Dict[str, Tuple[re.Pattern, str]] = {}
        for key in self.enabled_patterns:
            if key in self.DEFAULT_PATTERNS:
                regex, desc = self.DEFAULT_PATTERNS[key]
                self._compiled[key] = (re.compile(regex), desc)

    def match_format(self, text: str) -> Optional[Tuple[str, str]]:
        clean = normalize_plate_alnum(text)
        for key, (pattern, desc) in self._compiled.items():
            if pattern.match(clean):
                return key, desc
        return None

    def format_for_display(self, text: str) -> str:
        clean = normalize_plate_alnum(text)
        if len(clean) == 7 and clean[:3].isalpha() and clean[3:].isdigit():
            return f"{clean[:3]} {clean[3:]}"
        if len(clean) == 6 and clean[:3].isalpha() and clean[3:].isdigit():
            return f"{clean[:3]} {clean[3:]}"
        if len(clean) == 6 and clean[0].isalpha() and clean[1:4].isdigit() and clean[4:].isalpha():
            return f"{clean[0]} {clean[1:4]} {clean[4:]}"
        return clean

    def evaluate(
        self,
        decoded: DecodedPlateResult,
        frame_agreement_count: int = 1,
        active_registered_plates: Optional[Dict[str, Dict[str, object]]] = None,
    ) -> ValidationDecision:
        active_registered = active_registered_plates or {}
        raw = normalize_plate_alnum(decoded.raw_text)

        # 1. Noise Rejection
        if len(raw) < 4 or len(raw) > 10 or raw in NON_PLATE_WORDS or len(set(raw)) <= 1:
            return ValidationDecision(
                action="REJECT_NOISE",
                confirmed_plate=raw,
                display_plate=raw,
                confidence=decoded.plate_confidence,
                review_reason="Plate text is too short, uniform, or matches a noise word.",
            )

        top_cand = decoded.top_candidates[0] if decoded.top_candidates else CandidatePrediction(raw, decoded.plate_confidence)
        matched = self.match_format(top_cand.text)

        # 2. Check Exact Active Registered Match
        if top_cand.text in active_registered:
            reg_info = active_registered[top_cand.text]
            if decoded.plate_confidence >= self.min_auto_accept_conf and frame_agreement_count >= self.min_auto_accept_agreement:
                return ValidationDecision(
                    action="AUTO_ACCEPT",
                    confirmed_plate=top_cand.text,
                    display_plate=self.format_for_display(top_cand.text),
                    confidence=decoded.plate_confidence,
                    matched_format="registered_exact",
                    registered_match=reg_info,
                    candidates=decoded.top_candidates,
                )

        # 3. Check Confusable Match to an Active Registered Vehicle (e.g. 0566CP -> D566CP)
        # Never silently auto-replace: route to NEEDS_HUMAN_REVIEW with suggested match!
        for reg_plate, reg_info in active_registered.items():
            is_conf, diffs = are_confusable_neighbors(top_cand.text, reg_plate)
            if is_conf:
                diff_desc = ", ".join([f"pos {p}: '{c1}' vs '{c2}'" for p, c1, c2 in diffs])
                return ValidationDecision(
                    action="NEEDS_HUMAN_REVIEW",
                    confirmed_plate=top_cand.text,
                    display_plate=self.format_for_display(top_cand.text),
                    confidence=decoded.plate_confidence,
                    matched_format="confusable_registered_neighbor",
                    registered_match=reg_info,
                    candidates=decoded.top_candidates,
                    review_reason=f"Ambiguous match with Registered plate {reg_plate} ({diff_desc}). Guard confirmation required.",
                )

        # 4. Check Format Validation
        if matched:
            fmt_key, fmt_desc = matched
            # Check if there is an unresolved Z vs 2 / D vs 0 ambiguity in top candidates
            if len(decoded.top_candidates) > 1:
                cand2 = decoded.top_candidates[1]
                prob_diff = top_cand.probability - cand2.probability
                cand2_matched = self.match_format(cand2.text)
                if cand2_matched and prob_diff < 0.20:
                    return ValidationDecision(
                        action="NEEDS_HUMAN_REVIEW",
                        confirmed_plate=top_cand.text,
                        display_plate=self.format_for_display(top_cand.text),
                        confidence=decoded.plate_confidence,
                        matched_format=fmt_key,
                        candidates=decoded.top_candidates,
                        review_reason=f"Close tie between candidate '{top_cand.text}' and '{cand2.text}' (margin {prob_diff:.2f}). Guard verification required.",
                    )

            if decoded.plate_confidence >= self.min_auto_accept_conf and frame_agreement_count >= self.min_auto_accept_agreement:
                return ValidationDecision(
                    action="AUTO_ACCEPT",
                    confirmed_plate=top_cand.text,
                    display_plate=self.format_for_display(top_cand.text),
                    confidence=decoded.plate_confidence,
                    matched_format=fmt_key,
                    candidates=decoded.top_candidates,
                )
            else:
                return ValidationDecision(
                    action="NEEDS_HUMAN_REVIEW",
                    confirmed_plate=top_cand.text,
                    display_plate=self.format_for_display(top_cand.text),
                    confidence=decoded.plate_confidence,
                    matched_format=fmt_key,
                    candidates=decoded.top_candidates,
                    review_reason=f"Plate format valid ({fmt_desc}), but confidence ({decoded.plate_confidence:.2f}) or frame agreement ({frame_agreement_count}) is below threshold.",
                )

        # 5. Non-standard / Unrecognized Format
        return ValidationDecision(
            action="NEEDS_HUMAN_REVIEW",
            confirmed_plate=top_cand.text,
            display_plate=self.format_for_display(top_cand.text),
            confidence=decoded.plate_confidence,
            matched_format=None,
            candidates=decoded.top_candidates,
            review_reason="Plate text does not match standard Philippine LTO formats. Guard inspection required.",
        )


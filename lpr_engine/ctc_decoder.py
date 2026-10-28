"""
CTC Decoder with Prefix Beam Search & Confusable Character Ambiguity Detection.

Computes:
1. raw_text: exact greedy CTC prediction without alterations.
2. char_confidences & plate_confidence: probabilistic calibration.
3. top_candidates: prefix beam search with candidate probabilities.
4. ambiguous_positions: explicit diagnostic when confusable characters
   (Z vs 2, B vs 8, D vs 0, S vs 5, I vs 1) have low probability margins.
"""

from dataclasses import dataclass, field
import math
from typing import Dict, List, Optional, Tuple

import numpy as np
import torch

from .alphabet import BLANK_INDEX, CONFUSABLE_PEERS, IDX_TO_CHAR, NUM_CLASSES, normalize_plate_alnum


@dataclass
class CandidatePrediction:
    text: str
    probability: float
    is_valid_format: bool = False


@dataclass
class DecodedPlateResult:
    raw_text: str
    plate_confidence: float
    char_confidences: List[float] = field(default_factory=list)
    top_candidates: List[CandidatePrediction] = field(default_factory=list)
    ambiguous_positions: List[Dict[str, object]] = field(default_factory=list)
    time_steps_used: int = 0


class CTCDecoder:
    """
    Decodes LPRNet sequence output of shape (num_classes=37, T=18).
    """

    def __init__(
        self,
        beam_width: int = 5,
        ambiguity_margin_threshold: float = 0.22,
    ) -> None:
        self.beam_width = beam_width
        self.ambiguity_margin_threshold = ambiguity_margin_threshold

    def decode(
        self,
        logits_or_probs: torch.Tensor,
        is_probabilities: bool = False,
    ) -> DecodedPlateResult:
        """
        Decode a single sample (num_classes, T) into DecodedPlateResult.
        """
        if logits_or_probs.ndim == 3:
            logits_or_probs = logits_or_probs[0]

        if logits_or_probs.shape[0] != NUM_CLASSES and logits_or_probs.shape[1] == NUM_CLASSES:
            logits_or_probs = logits_or_probs.transpose(0, 1)

        num_classes, t_steps = logits_or_probs.shape

        if is_probabilities:
            probs = logits_or_probs.detach().cpu().numpy()
        else:
            probs = torch.softmax(logits_or_probs, dim=0).detach().cpu().numpy()

        # 1. Greedy Best-Path Decoding
        best_indices = np.argmax(probs, axis=0)  # (T,)
        raw_chars: List[str] = []
        char_confidences: List[float] = []
        raw_spans: List[List[int]] = []

        prev_idx = BLANK_INDEX
        current_span: List[int] = []

        for t in range(t_steps):
            idx = int(best_indices[t])
            if idx != prev_idx:
                if current_span and raw_chars:
                    span_confs = [float(probs[best_indices[st], st]) for st in current_span]
                    char_confidences.append(float(np.mean(span_confs)))
                    raw_spans.append(current_span)
                    current_span = []

                if idx != BLANK_INDEX:
                    raw_chars.append(IDX_TO_CHAR[idx])
                    current_span.append(t)
            else:
                if idx != BLANK_INDEX:
                    current_span.append(t)

            prev_idx = idx

        if current_span and raw_chars and len(char_confidences) < len(raw_chars):
            span_confs = [float(probs[best_indices[st], st]) for st in current_span]
            char_confidences.append(float(np.mean(span_confs)))
            raw_spans.append(current_span)

        raw_text = "".join(raw_chars)
        plate_confidence = float(np.mean(char_confidences)) if char_confidences else 0.0

        # 2. Check Confusable Character Ambiguities at Each Emitted Position
        ambiguous_positions: List[Dict[str, object]] = []
        for pos_idx, ch in enumerate(raw_chars):
            peers = CONFUSABLE_PEERS.get(ch, [])
            if not peers or pos_idx >= len(raw_spans):
                continue

            span = raw_spans[pos_idx]
            top_prob = float(np.max([probs[best_indices[t], t] for t in span]))

            for alt_ch in peers:
                alt_idx = [k for k, v in IDX_TO_CHAR.items() if v == alt_ch][0]
                alt_prob = float(np.max([probs[alt_idx, t] for t in span]))
                margin = top_prob - alt_prob

                if margin < self.ambiguity_margin_threshold:
                    ambiguous_positions.append({
                        "position": pos_idx,
                        "primary_char": ch,
                        "primary_prob": round(top_prob, 3),
                        "alternate_char": alt_ch,
                        "alternate_prob": round(alt_prob, 3),
                        "margin": round(margin, 3),
                    })

        # 3. Prefix Beam Search Decoding
        candidates = self._prefix_beam_search(probs, self.beam_width)

        top_candidates = [
            CandidatePrediction(
                text=cand_text,
                probability=round(cand_prob, 4),
            )
            for cand_text, cand_prob in candidates
        ]

        if not top_candidates:
            top_candidates = [CandidatePrediction(text=raw_text, probability=plate_confidence)]

        return DecodedPlateResult(
            raw_text=raw_text,
            plate_confidence=round(plate_confidence, 4),
            char_confidences=[round(c, 4) for c in char_confidences],
            top_candidates=top_candidates,
            ambiguous_positions=ambiguous_positions,
            time_steps_used=t_steps,
        )

    def _prefix_beam_search(
        self,
        probs: np.ndarray,
        beam_width: int,
    ) -> List[Tuple[str, float]]:
        """
        Prefix beam search across CTC probability lattice.
        Returns sorted list of (decoded_string, probability).
        """
        num_classes, t_steps = probs.shape
        # prefixes: prefix_str -> (p_blank, p_non_blank)
        prefixes: Dict[str, Tuple[float, float]] = {"": (1.0, 0.0)}

        for t in range(t_steps):
            next_prefixes: Dict[str, Tuple[float, float]] = {}

            # Probability of blank at timestep t
            p_blank_t = float(probs[BLANK_INDEX, t])

            for prefix, (pb, pnb) in prefixes.items():
                p_total = pb + pnb
                if p_total <= 1e-12:
                    continue

                # 1. Blank transition
                nb_pb, nb_pnb = next_prefixes.get(prefix, (0.0, 0.0))
                next_prefixes[prefix] = (nb_pb + p_total * p_blank_t, nb_pnb)

                # 2. Non-blank transitions
                for c in range(BLANK_INDEX):
                    p_c = float(probs[c, t])
                    if p_c <= 1e-6:
                        continue

                    ch = IDX_TO_CHAR[c]
                    end_ch = prefix[-1] if prefix else None

                    if ch == end_ch:
                        # Same character: must come from blank to create consecutive repeat
                        new_pref = prefix + ch
                        np_b, np_nb = next_prefixes.get(new_pref, (0.0, 0.0))
                        next_prefixes[new_pref] = (np_b, np_nb + pb * p_c)

                        # Coming from non-blank keeps same prefix
                        cp_b, cp_nb = next_prefixes.get(prefix, (0.0, 0.0))
                        next_prefixes[prefix] = (cp_b, cp_nb + pnb * p_c)
                    else:
                        new_pref = prefix + ch
                        np_b, np_nb = next_prefixes.get(new_pref, (0.0, 0.0))
                        next_prefixes[new_pref] = (np_b, np_nb + p_total * p_c)

            # Prune beam to beam_width
            sorted_prefixes = sorted(
                next_prefixes.items(),
                key=lambda item: item[1][0] + item[1][1],
                reverse=True,
            )
            prefixes = dict(sorted_prefixes[: beam_width * 2])

        results = [
            (pref, float(pb + pnb))
            for pref, (pb, pnb) in prefixes.items()
            if pref
        ]
        results.sort(key=lambda x: x[1], reverse=True)

        # Normalize sum of top probabilities
        total_p = sum(p for _, p in results[:beam_width]) or 1.0
        normalized = [(text, p / total_p) for text, p in results[:beam_width]]
        return normalized

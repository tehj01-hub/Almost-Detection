"""
Consistent Philippine License Plate Alphabet & Visually Confusable Character Definitions.

Used identically during LPRNet dataset preparation, CTC training, validation, and live inference.
Supports live hot-reloading without server restarts.
"""

import re
from typing import Dict, List, Tuple

# 36 alphanumeric characters used across Philippine LTO standard, legacy, conduction, and motorcycle plates
PH_CHARS: str = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"
BLANK_CHAR: str = "-"

# Index 36 is reserved as the CTC blank token
ALL_TOKENS: List[str] = list(PH_CHARS) + [BLANK_CHAR]
BLANK_INDEX: int = len(PH_CHARS)  # 36
NUM_CLASSES: int = len(ALL_TOKENS)  # 37

CHAR_TO_IDX: Dict[str, int] = {ch: idx for idx, ch in enumerate(PH_CHARS)}
IDX_TO_CHAR: Dict[int, str] = {idx: ch for idx, ch in enumerate(PH_CHARS)}

# Visually similar character pairs explicitly monitored during decoding & evaluation.
# IMPORTANT: These are NEVER used for blind automatic replacement; they are used to detect
# low-margin CTC ambiguities (e.g., Z vs 2) and route uncertain reads to human review.
CONFUSABLE_PAIRS: List[Tuple[str, str]] = [
    ("Z", "2"),
    ("B", "8"),
    ("D", "0"),
    ("O", "0"),
    ("Q", "0"),
    ("S", "5"),
    ("I", "1"),
    ("L", "1"),
    ("G", "6"),
]

CONFUSABLE_PEERS: Dict[str, List[str]] = {}
for _a, _b in CONFUSABLE_PAIRS:
    CONFUSABLE_PEERS.setdefault(_a, []).append(_b)
    CONFUSABLE_PEERS.setdefault(_b, []).append(_a)


def normalize_plate_alnum(text: str) -> str:
    """
    Normalize a plate string by uppercasing and stripping spaces/hyphens/separators
    without altering or substituting any alphanumeric characters.
    """
    if not text:
        return ""
    return re.sub(r"[^A-Z0-9]", "", text.upper().strip())


def encode_plate_text(text: str) -> List[int]:
    """
    Convert a ground-truth Philippine plate string into LPRNet CTC integer indices.
    Raises ValueError if an unsupported character remains after normalization.
    """
    clean = normalize_plate_alnum(text)
    if not clean:
        raise ValueError(f"Plate text '{text}' is empty after alphanumeric normalization.")
    indices: List[int] = []
    for ch in clean:
        if ch not in CHAR_TO_IDX:
            raise ValueError(f"Unsupported character '{ch}' in plate '{text}'.")
        indices.append(CHAR_TO_IDX[ch])
    return indices


def are_confusable_neighbors(plate_a: str, plate_b: str) -> Tuple[bool, List[Tuple[int, str, str]]]:
    """
    Check if two equal-length normalized plates differ ONLY at visually confusable
    character positions (such as Z/2, B/8, D/0, S/5, I/1).

    Returns:
        (is_confusable_neighbor, list_of_(pos, char_a, char_b))
    """
    a = normalize_plate_alnum(plate_a)
    b = normalize_plate_alnum(plate_b)
    if not a or len(a) != len(b) or a == b:
        return False, []

    diffs: List[Tuple[int, str, str]] = []
    for idx, (ca, cb) in enumerate(zip(a, b)):
        if ca != cb:
            if cb in CONFUSABLE_PEERS.get(ca, []):
                diffs.append((idx, ca, cb))
            else:
                return False, []

    return len(diffs) > 0, diffs

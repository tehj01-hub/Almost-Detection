"""
Comparative OCR Benchmark Evaluation Suite (Section 6 of Project Specification).

Compares:
1. Existing EasyOCR baseline.
2. PaddleOCR (PP-OCRv4) baseline.
3. LPRNet fine-tuned for Philippine plates (with preprocessing ablation).

Reports (formatted for thesis chapters):
- Exact plate recognition accuracy (%)
- Character-level accuracy (%)
- Character Error Rate (CER)
- Recognition failure rate (%)
- Average inference time per plate (ms)
- Performance under daytime, nighttime, blurry, angled, and low-light conditions
- Confusion rates for Z/2, B/8, D/0, S/5, and I/1
"""

import argparse
import csv
import json
import os
import sys
import time
from typing import Any, Dict, List, Tuple

import cv2
import numpy as np
import torch

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from lpr_engine import (
    CTCDecoder,
    LPRNetRecognizer,
    PlatePreprocessor,
    load_lprnet_checkpoint,
    normalize_plate_alnum,
    select_inference_device,
)


def compute_edit_distance(s1: str, s2: str) -> Tuple[int, int, int]:
    """Levenshtein distance with breakdown of (substitutions, deletions, insertions)."""
    m, n = len(s1), len(s2)
    dp = [[(0, 0, 0) for _ in range(n + 1)] for _ in range(m + 1)]

    for i in range(1, m + 1):
        dp[i][0] = (0, i, 0)  # i deletions
    for j in range(1, n + 1):
        dp[0][j] = (0, 0, j)  # j insertions

    for i in range(1, m + 1):
        for j in range(1, n + 1):
            if s1[i - 1] == s2[j - 1]:
                dp[i][j] = dp[i - 1][j - 1]
            else:
                sub = (dp[i - 1][j - 1][0] + 1, dp[i - 1][j - 1][1], dp[i - 1][j - 1][2])
                dele = (dp[i - 1][j][0], dp[i - 1][j][1] + 1, dp[i - 1][j][2])
                ins = (dp[i][j - 1][0], dp[i][j - 1][1], dp[i][j - 1][2] + 1)
                dp[i][j] = min((sub, dele, ins), key=lambda x: sum(x))

    return dp[m][n]


class EasyOCREvaluator:
    def __init__(self, use_gpu: bool = False):
        import easyocr
        self.reader = easyocr.Reader(["en"], gpu=use_gpu)

    def predict(self, bgr: np.ndarray) -> str:
        # Match current detect_plate_server.py enhanced CLAHE pipeline
        gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
        clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
        enhanced = clahe.apply(gray)
        res = self.reader.readtext(enhanced, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
        if not res:
            res = self.reader.readtext(bgr, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
        if not res:
            return ""
        return normalize_plate_alnum(" ".join(t[1] for t in res))


class PaddleOCREvaluator:
    def __init__(self, use_gpu: bool = False):
        from paddleocr import PaddleOCR
        self.reader = PaddleOCR(use_angle_cls=False, lang="en", show_log=False, use_gpu=use_gpu)

    def predict(self, bgr: np.ndarray) -> str:
        res = self.reader.ocr(bgr, cls=False)
        if not res or not res[0]:
            return ""
        tokens = [line[1][0] for line in res[0] if line and len(line) > 1]
        return normalize_plate_alnum(" ".join(tokens))


def evaluate_model(name: str, predictor_fn, dataset: List[Dict[str, Any]]) -> Dict[str, Any]:
    total_samples = len(dataset)
    if total_samples == 0:
        return {"error": "Dataset is empty"}

    exact_matches = 0
    total_gt_chars = 0
    total_correct_chars = 0
    total_substitutions = 0
    total_deletions = 0
    total_insertions = 0
    failed_recognitions = 0
    latencies: List[float] = []

    condition_exact: Dict[str, int] = {}
    condition_totals: Dict[str, int] = {}
    confusions: Dict[Tuple[str, str], int] = {}

    for sample in dataset:
        bgr = sample["image"]
        gt = sample["ground_truth"]
        condition = sample.get("condition", "standard")

        for c in condition.split(";"):
            c = c.strip()
            condition_totals[c] = condition_totals.get(c, 0) + 1

        t0 = time.perf_counter()
        pred = predictor_fn(bgr)
        elapsed_ms = (time.perf_counter() - t0) * 1000.0
        latencies.append(elapsed_ms)

        if not pred:
            failed_recognitions += 1

        if pred == gt:
            exact_matches += 1
            for c in condition.split(";"):
                c = c.strip()
                condition_exact[c] = condition_exact.get(c, 0) + 1

        s, d, i = compute_edit_distance(pred, gt)
        total_substitutions += s
        total_deletions += d
        total_insertions += i
        total_gt_chars += len(gt)
        total_correct_chars += max(0, len(gt) - (s + d))

        # Confusable character pair tracking
        for p_ch, g_ch in zip(pred, gt):
            if p_ch != g_ch:
                confusions[(g_ch, p_ch)] = confusions.get((g_ch, p_ch), 0) + 1

    exact_acc = (exact_matches / total_samples) * 100.0
    char_acc = (total_correct_chars / max(1, total_gt_chars)) * 100.0
    cer = (total_substitutions + total_deletions + total_insertions) / max(1.0, float(total_gt_chars))
    fail_rate = (failed_recognitions / total_samples) * 100.0
    avg_latency = float(np.mean(latencies))
    median_latency = float(np.median(latencies))

    condition_acc = {
        cond: round((condition_exact.get(cond, 0) / max(1, cnt)) * 100.0, 1)
        for cond, cnt in condition_totals.items()
    }

    # Extract target confusions: Z/2, B/8, D/0, S/5, I/1
    target_pairs = [
        ("Z", "2"), ("2", "Z"),
        ("B", "8"), ("8", "B"),
        ("D", "0"), ("0", "D"),
        ("O", "0"), ("0", "O"),
        ("S", "5"), ("5", "S"),
        ("I", "1"), ("1", "I"),
    ]
    target_confusions = {
        f"{gt}->{pred}": confusions.get((gt, pred), 0)
        for gt, pred in target_pairs
    }

    return {
        "model": name,
        "total_samples": total_samples,
        "exact_plate_accuracy": round(exact_acc, 2),
        "character_accuracy": round(char_acc, 2),
        "character_error_rate_cer": round(cer, 4),
        "failure_rate": round(fail_rate, 2),
        "avg_latency_ms": round(avg_latency, 2),
        "median_latency_ms": round(median_latency, 2),
        "condition_accuracy": condition_acc,
        "confusable_character_rates": target_confusions,
    }


def main():
    parser = argparse.ArgumentParser(description="Evaluate OCR Models on Held-Out Validation Set")
    parser.add_argument("--val-dir", type=str, default=os.path.join(BASE_DIR, "datasets", "ph_plates"), help="Directory containing annotations.csv and images/")
    parser.add_argument("--weights", type=str, default=os.path.join(BASE_DIR, "weights", "lprnet_ph_best.pt"), help="LPRNet weights path")
    parser.add_argument("--output-report", type=str, default=os.path.join(BASE_DIR, "reports", "thesis_evaluation.json"), help="Output JSON path")
    parser.add_argument("--device", type=str, default=None)
    args = parser.parse_args()

    csv_path = os.path.join(args.val_dir, "annotations.csv")
    images_dir = os.path.join(args.val_dir, "images")

    if not os.path.exists(csv_path):
        print(f"[EVAL] Error: {csv_path} not found.")
        print("[EVAL] Run 'python tools/collect_and_label_crops.py --serve-labeler' to prepare your validation dataset.")
        return

    dataset = []
    with open(csv_path, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for r in reader:
            fname = r.get("filename", "")
            gt = normalize_plate_alnum(r.get("ground_truth", ""))
            split = r.get("split", "val").lower()
            if fname and gt and split in ["val", "test", "all"]:
                img_p = os.path.join(images_dir, fname)
                bgr = cv2.imread(img_p)
                if bgr is not None:
                    dataset.append({
                        "filename": fname,
                        "ground_truth": gt,
                        "condition": r.get("condition", "standard"),
                        "image": bgr,
                    })

    print(f"[EVAL] Loaded {len(dataset)} validation samples from {csv_path}")
    if len(dataset) == 0:
        return

    results = []

    # 1. EasyOCR
    try:
        print("[EVAL] Evaluating Baseline 1: EasyOCR...")
        easy_eval = EasyOCREvaluator(use_gpu=torch.cuda.is_available())
        results.append(evaluate_model("EasyOCR (Current Baseline)", easy_eval.predict, dataset))
    except Exception as e:
        print(f"[EVAL] EasyOCR evaluation skipped: {e}")

    # 2. PaddleOCR
    try:
        print("[EVAL] Evaluating Baseline 2: PaddleOCR...")
        paddle_eval = PaddleOCREvaluator(use_gpu=torch.cuda.is_available())
        results.append(evaluate_model("PaddleOCR (PP-OCRv4)", paddle_eval.predict, dataset))
    except Exception as e:
        print(f"[EVAL] PaddleOCR not installed; skipping. (Install via 'pip install paddlepaddle paddleocr')")

    # 3. LPRNet (Fine-Tuned) with Smart Preprocessing
    if os.path.exists(args.weights):
        print("[EVAL] Evaluating Model 3: LPRNet (Philippine CTC + Conditional Preprocessing)...")
        lpr_smart = LPRNetRecognizer(checkpoint_path=args.weights, preprocessing_mode="conditional_smart")
        results.append(evaluate_model("LPRNet (Conditional Preprocessing)", lambda bgr: lpr_smart.recognize(bgr).raw_text, dataset))

        # Ablation: LPRNet with raw stretch resize
        print("[EVAL] Evaluating Ablation: LPRNet (Raw Stretch Resize)...")
        lpr_raw = LPRNetRecognizer(checkpoint_path=args.weights, preprocessing_mode="raw_resize")
        results.append(evaluate_model("LPRNet (Ablation: Raw Resize)", lambda bgr: lpr_raw.recognize(bgr).raw_text, dataset))
    else:
        print(f"[EVAL] LPRNet weights not found at {args.weights}. Train via train_lprnet_ph.py first.")

    os.makedirs(os.path.dirname(os.path.abspath(args.output_report)), exist_ok=True)
    with open(args.output_report, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)

    # Print Markdown Summary Table
    print("\n" + "=" * 80)
    print("  THESIS EVALUATION BENCHMARK RESULTS (Section 6 Report)")
    print("=" * 80)
    print(f"{'Model':<35} | {'Exact Acc (%)':<14} | {'Char Acc (%)':<13} | {'CER':<8} | {'Latency (ms)':<12}")
    print("-" * 80)
    for r in results:
        print(f"{r['model']:<35} | {r['exact_plate_accuracy']:<14.2f} | {r['character_accuracy']:<13.2f} | {r['character_error_rate_cer']:<8.4f} | {r['avg_latency_ms']:<12.1f}")
    print("=" * 80)
    print(f"[EVAL] Detailed report saved to: {args.output_report}")


if __name__ == "__main__":
    main()


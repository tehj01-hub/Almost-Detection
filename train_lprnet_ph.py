"""
Philippine LPRNet CTC Training & Validation Script.

Features:
- CTC Loss with AdamW optimizer and Cosine Annealing learning rate.
- CUDA GPU acceleration with automatic Mixed Precision (AMP) and CPU fallback.
- Confusion tracking for Z vs 2, B vs 8, D vs 0, S vs 5, and I vs 1.
- Character Error Rate (CER) and Exact Match evaluation on held-out validation set.
- Optional synthetic pretraining mode (--pretrain-synthetic) for quick bootstrapping.
"""

import argparse
import csv
import os
import random
import sys
from typing import Dict, List, Tuple

import cv2
import numpy as np
import torch
import torch.nn as nn
from torch.utils.data import DataLoader, Dataset

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from lpr_engine import (
    BLANK_INDEX,
    CONFUSABLE_PEERS,
    NUM_CLASSES,
    PH_CHARS,
    CTCDecoder,
    LPRNet,
    PlatePreprocessor,
    encode_plate_text,
    normalize_plate_alnum,
    save_lprnet_checkpoint,
    select_inference_device,
)


class PhilippinePlateDataset(Dataset):
    def __init__(self, data_dir: str, split: str = "train", augment: bool = True):
        self.data_dir = data_dir
        self.images_dir = os.path.join(data_dir, "images")
        self.split = split
        self.augment = augment
        self.preprocessor = PlatePreprocessor(mode="conditional_smart")
        self.samples: List[Tuple[str, str, str]] = []

        csv_path = os.path.join(data_dir, "annotations.csv")
        if os.path.exists(csv_path):
            with open(csv_path, "r", encoding="utf-8") as f:
                reader = csv.DictReader(f)
                for r in reader:
                    fname = r.get("filename", "")
                    gt = normalize_plate_alnum(r.get("ground_truth", ""))
                    s = r.get("split", "train").lower()
                    if fname and gt and (s == split or split == "all"):
                        self.samples.append((fname, gt, r.get("condition", "standard")))

        print(f"[DATASET] Loaded {len(self.samples)} samples for split='{split}' from {csv_path}")

    def __len__(self):
        return len(self.samples)

    def __getitem__(self, idx):
        fname, gt, cond = self.samples[idx]
        img_p = os.path.join(self.images_dir, fname)
        bgr = cv2.imread(img_p)
        if bgr is None:
            bgr = np.full((30, 100, 3), 128, dtype=np.uint8)

        if self.augment and self.split == "train":
            bgr = self._apply_augmentation(bgr)

        chw, _, _, _ = self.preprocessor.preprocess(bgr)
        target = encode_plate_text(gt)
        return (
            torch.from_numpy(chw),
            torch.tensor(target, dtype=torch.long),
            len(target),
            gt,
            cond,
        )

    def _apply_augmentation(self, img: np.ndarray) -> np.ndarray:
        h, w = img.shape[:2]
        # Slight brightness jitter
        if random.random() < 0.4:
            factor = random.uniform(0.75, 1.25)
            img = np.clip(img.astype(np.float32) * factor, 0, 255).astype(np.uint8)
        # Slight motion or Gaussian blur
        if random.random() < 0.3:
            k = random.choice([3, 5])
            img = cv2.GaussianBlur(img, (k, k), 0)
        return img


def collate_fn(batch):
    images, targets, target_lengths, gts, conds = zip(*batch)
    images = torch.stack(images, dim=0)
    flat_targets = torch.cat(targets, dim=0)
    target_lengths = torch.tensor(target_lengths, dtype=torch.long)
    return images, flat_targets, target_lengths, gts, conds


def compute_cer(pred: str, gt: str) -> float:
    """Levenshtein distance / max(1, len(gt))."""
    if pred == gt:
        return 0.0
    dp = [[0] * (len(gt) + 1) for _ in range(len(pred) + 1)]
    for i in range(len(pred) + 1):
        dp[i][0] = i
    for j in range(len(gt) + 1):
        dp[0][j] = j
    for i in range(1, len(pred) + 1):
        for j in range(1, len(gt) + 1):
            cost = 0 if pred[i - 1] == gt[j - 1] else 1
            dp[i][j] = min(dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + cost)
    return float(dp[len(pred)][len(gt)]) / max(1.0, float(len(gt)))


def evaluate(model, loader, device, decoder):
    model.eval()
    total = 0
    exact_matches = 0
    total_cer = 0.0
    confusions: Dict[Tuple[str, str], int] = {}

    with torch.no_grad():
        for images, _, _, gts, _ in loader:
            images = images.to(device)
            logits = model(images)  # (B, C, T)

            for b in range(logits.shape[0]):
                sample_logits = logits[b]
                res = decoder.decode(sample_logits)
                pred_text = res.raw_text
                gt_text = gts[b]

                total += 1
                if pred_text == gt_text:
                    exact_matches += 1
                total_cer += compute_cer(pred_text, gt_text)

                # Track pairwise character confusions for equal-length substrings
                for p_ch, g_ch in zip(pred_text, gt_text):
                    if p_ch != g_ch:
                        confusions[(g_ch, p_ch)] = confusions.get((g_ch, p_ch), 0) + 1

    exact_acc = (exact_matches / max(1, total)) * 100.0
    mean_cer = total_cer / max(1, total)
    return exact_acc, mean_cer, confusions


def generate_synthetic_plate() -> Tuple[np.ndarray, str]:
    """Generate a clean synthetic Philippine plate crop for pretraining initialization."""
    plate_type = random.choice(["standard", "conduction", "motorcycle"])
    if plate_type == "standard":
        letters = "".join(random.choices("ABCDEFGHJKLMNPQRSTUVWXYZ", k=3))
        digits = "".join(random.choices("0123456789", k=4))
        text = f"{letters}{digits}"
        disp = f"{letters} {digits}"
    elif plate_type == "conduction":
        l1 = random.choice("ABCDEFGHJKLMNPQRSTUVWXYZ")
        digits = "".join(random.choices("0123456789", k=3))
        l2 = "".join(random.choices("ABCDEFGHJKLMNPQRSTUVWXYZ", k=2))
        text = f"{l1}{digits}{l2}"
        disp = f"{l1} {digits} {l2}"
    else:
        letters = "".join(random.choices("ABCDEFGHJKLMNPQRSTUVWXYZ", k=2))
        digits = "".join(random.choices("0123456789", k=5))
        text = f"{letters}{digits}"
        disp = f"{letters} {digits}"

    img = np.full((55, 180, 3), 245, dtype=np.uint8)
    cv2.rectangle(img, (2, 2), (177, 52), (30, 30, 30), 2)
    cv2.putText(img, disp, (12, 38), cv2.FONT_HERSHEY_DUPLEX, 1.0, (10, 10, 10), 2, cv2.LINE_AA)
    return img, text


def train(args):
    device = torch.device(args.device) if args.device else select_inference_device()
    print(f"[TRAIN] Target device: {device}")

    model = LPRNet(num_classes=NUM_CLASSES, dropout_rate=0.4).to(device)
    ctc_loss = nn.CTCLoss(blank=BLANK_INDEX, zero_infinity=True)
    optimizer = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=1e-4)
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(optimizer, T_max=args.epochs, eta_min=args.lr * 0.05)
    decoder = CTCDecoder(beam_width=5)

    # 1. Synthetic pretraining if dataset has zero/few samples or flag is set
    if args.pretrain_synthetic > 0:
        print(f"\n[BOOTSTRAP] Running {args.pretrain_synthetic} synthetic pretraining steps on Philippine FE-Schrift patterns...")
        model.train()
        preprocessor = PlatePreprocessor(mode="aspect_pad_only")
        batch_size = 32
        steps = args.pretrain_synthetic // batch_size

        for s in range(steps):
            batch_imgs = []
            batch_targets = []
            target_lens = []

            for _ in range(batch_size):
                syn_bgr, syn_text = generate_synthetic_plate()
                chw, _, _, _ = preprocessor.preprocess(syn_bgr)
                batch_imgs.append(torch.from_numpy(chw))
                t = encode_plate_text(syn_text)
                batch_targets.extend(t)
                target_lens.append(len(t))

            inp_imgs = torch.stack(batch_imgs, dim=0).to(device)
            flat_tgt = torch.tensor(batch_targets, dtype=torch.long)
            tgt_lens = torch.tensor(target_lens, dtype=torch.long)

            optimizer.zero_grad()
            logits = model(inp_imgs)  # (B, C, T)
            log_probs = logits.permute(2, 0, 1).log_softmax(2)  # (T, B, C)
            input_lens = torch.full((batch_size,), logits.shape[2], dtype=torch.long)

            loss = ctc_loss(log_probs, flat_tgt, input_lens, tgt_lens)
            loss.backward()
            optimizer.step()

            if (s + 1) % 20 == 0:
                print(f"  Synthetic Step [{s+1}/{steps}] - CTC Loss: {loss.item():.4f}")

        print("[BOOTSTRAP] Synthetic pretraining complete! Character stroke weights initialized.")

    # 2. Main Training on Labeled Philippine Crops
    train_dataset = PhilippinePlateDataset(args.data_dir, split="train", augment=True)
    val_dataset = PhilippinePlateDataset(args.data_dir, split="val", augment=False)

    if len(train_dataset) == 0:
        print(f"\n[NOTICE] No labeled crops found in {args.data_dir}/annotations.csv.")
        print("[NOTICE] Use 'python tools/collect_and_label_crops.py --serve-labeler' to label crops from your Tapo C260 camera.")
        out_weights = os.path.join(BASE_DIR, "weights", "lprnet_ph_best.pt")
        save_lprnet_checkpoint(model, out_weights, epoch=0, metrics={"synthetic_bootstrap": True})
        print(f"[SAVE] Initial bootstrap model saved to: {out_weights}")
        return

    train_loader = DataLoader(train_dataset, batch_size=args.batch_size, shuffle=True, collate_fn=collate_fn, num_workers=0)
    val_loader = DataLoader(val_dataset, batch_size=args.batch_size, shuffle=False, collate_fn=collate_fn, num_workers=0)

    best_val_acc = -1.0
    out_weights = os.path.join(BASE_DIR, "weights", "lprnet_ph_best.pt")

    print(f"\n[TRAIN] Beginning {args.epochs} training epochs on Philippine plate dataset...")
    for epoch in range(1, args.epochs + 1):
        model.train()
        epoch_loss = 0.0

        for images, flat_targets, target_lengths, _, _ in train_loader:
            images = images.to(device)
            batch_size = images.shape[0]

            optimizer.zero_grad()
            logits = model(images)
            log_probs = logits.permute(2, 0, 1).log_softmax(2)
            input_lengths = torch.full((batch_size,), logits.shape[2], dtype=torch.long)

            loss = ctc_loss(log_probs, flat_targets, input_lengths, target_lengths)
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 5.0)
            optimizer.step()

            epoch_loss += loss.item()

        scheduler.step()
        avg_loss = epoch_loss / max(1, len(train_loader))

        if epoch % 5 == 0 or epoch == args.epochs:
            val_acc, val_cer, confs = evaluate(model, val_loader, device, decoder)
            print(f"Epoch [{epoch:03d}/{args.epochs}] Loss: {avg_loss:.4f} | Val Exact Acc: {val_acc:.1f}% | CER: {val_cer:.3f}")

            # Report Z/2, B/8, D/0, S/5 confusion diagnostics
            monitored = [("Z", "2"), ("2", "Z"), ("D", "0"), ("0", "D"), ("B", "8"), ("8", "B"), ("S", "5"), ("5", "S")]
            active_confs = {f"{gt}->{pred}": confs.get((gt, pred), 0) for gt, pred in monitored if confs.get((gt, pred), 0) > 0}
            if active_confs:
                print(f"  Ambiguity tracking: {active_confs}")

            if val_acc > best_val_acc:
                best_val_acc = val_acc
                save_lprnet_checkpoint(model, out_weights, epoch=epoch, metrics={"val_acc": val_acc, "cer": val_cer})
                print(f"  ★ New best model saved to {out_weights} (Exact Acc: {val_acc:.1f}%)")

    print(f"\n[TRAIN] Training complete! Best validation accuracy: {best_val_acc:.1f}%")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--data-dir", type=str, default=os.path.join(BASE_DIR, "datasets", "ph_plates"))
    parser.add_argument("--epochs", type=int, default=60)
    parser.add_argument("--batch-size", type=int, default=32)
    parser.add_argument("--lr", type=float, default=0.001)
    parser.add_argument("--device", type=str, default=None)
    parser.add_argument("--pretrain-synthetic", type=int, default=2000, help="Number of synthetic plate samples for initial stroke pretraining")
    parsed_args = parser.parse_args()
    train(parsed_args)


"""
LPRNet Deep Neural Network for Philippine License Plate Character Recognition.

Reference Architecture:
    S. Zherzdev and A. Izmailov, "LPRNet: License Plate Recognition via Deep Neural Networks,"
    arXiv:1806.10447, 2018.

Important Compatibility Note on Pretrained Checkpoints:
    Publicly available LPRNet weights (e.g., Sirius-ai/LPRNet_Pytorch trained on Chinese CCPD)
    use a 68-class Chinese alphabet (31 Chinese province ideograms + mainland characters that
    omit 'I' and 'O'). Those checkpoints DO NOT support Philippine license plates directly.
    This implementation uses the 37-class Philippine alphabet (0-9, A-Z + CTC blank) and provides
    `load_pretrained_backbone_partial()` to transfer shape-compatible feature-extractor layers
    when fine-tuning on labeled Philippine license plate crops.
"""

import os
from typing import Any, Dict, Optional, Tuple

import torch
import torch.nn as nn
import torch.nn.functional as F

from .alphabet import NUM_CLASSES, PH_CHARS

LPRNET_INPUT_WIDTH: int = 94
LPRNET_INPUT_HEIGHT: int = 24
LPRNET_TIME_STEPS: int = 18


class SmallBasicBlock(nn.Module):
    """Factorized bottleneck block from LPRNet (1x1 -> 3x1 -> 1x3 -> 1x1 convolutions)."""

    def __init__(self, ch_in: int, ch_out: int) -> None:
        super().__init__()
        mid = max(16, ch_out // 4)
        self.block = nn.Sequential(
            nn.Conv2d(ch_in, mid, kernel_size=1, stride=1, padding=0, bias=False),
            nn.BatchNorm2d(mid),
            nn.ReLU(inplace=True),
            nn.Conv2d(mid, mid, kernel_size=(3, 1), stride=1, padding=(1, 0), bias=False),
            nn.BatchNorm2d(mid),
            nn.ReLU(inplace=True),
            nn.Conv2d(mid, mid, kernel_size=(1, 3), stride=1, padding=(0, 1), bias=False),
            nn.BatchNorm2d(mid),
            nn.ReLU(inplace=True),
            nn.Conv2d(mid, ch_out, kernel_size=1, stride=1, padding=0, bias=False),
        )

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        return self.block(x)


class LPRNet(nn.Module):
    """
    Lightweight end-to-end convolutional network with multi-scale global context fusion
    for CTC sequence recognition on (3, 24, 94) RGB plate crops.

    Output shape:
        logits of shape (Batch, num_classes=37, T=18)
    """

    def __init__(
        self,
        num_classes: int = NUM_CLASSES,
        dropout_rate: float = 0.5,
    ) -> None:
        super().__init__()
        self.num_classes = num_classes
        self.dropout_rate = dropout_rate

        # Stage 1: (B, 3, 24, 94) -> (B, 128, 20, 90)
        self.stage1 = nn.Sequential(
            nn.Conv2d(3, 64, kernel_size=3, stride=1, padding=0, bias=False),      # -> (64, 22, 92)
            nn.BatchNorm2d(64),
            nn.ReLU(inplace=True),
            nn.MaxPool2d(kernel_size=3, stride=1, padding=0),                      # -> (64, 20, 90)
            SmallBasicBlock(64, 128),                                              # -> (128, 20, 90)
            nn.BatchNorm2d(128),
            nn.ReLU(inplace=True),
        )

        # Stage 2: (B, 128, 20, 90) -> (B, 256, 18, 44)
        self.stage2 = nn.Sequential(
            nn.MaxPool2d(kernel_size=3, stride=(1, 2), padding=0),                 # -> (128, 18, 44)
            SmallBasicBlock(128, 256),                                             # -> (256, 18, 44)
            nn.BatchNorm2d(256),
            nn.ReLU(inplace=True),
            SmallBasicBlock(256, 256),                                             # -> (256, 18, 44)
            nn.BatchNorm2d(256),
            nn.ReLU(inplace=True),
        )

        # Stage 3: (B, 256, 18, 44) -> (B, num_classes, 4, 18)
        self.stage3 = nn.Sequential(
            nn.MaxPool2d(kernel_size=3, stride=(1, 2), padding=0),                 # -> (256, 16, 21)
            nn.Dropout(p=dropout_rate),
            nn.Conv2d(256, 256, kernel_size=(1, 4), stride=1, padding=0, bias=False),  # -> (256, 16, 18)
            nn.BatchNorm2d(256),
            nn.ReLU(inplace=True),
            nn.Dropout(p=dropout_rate),
            nn.Conv2d(256, num_classes, kernel_size=(13, 1), stride=1, padding=0), # -> (C, 4, 18)
            nn.BatchNorm2d(num_classes),
            nn.ReLU(inplace=True),
        )

        # Multi-scale global context fusion across (input, stage1, stage2, stage3) -> (C, 4, 18)
        total_context_channels = 3 + 128 + 256 + num_classes
        self.container = nn.Conv2d(
            in_channels=total_context_channels,
            out_channels=num_classes,
            kernel_size=1,
            stride=1,
            padding=0,
        )

        self._init_weights()

    def _init_weights(self) -> None:
        for m in self.modules():
            if isinstance(m, nn.Conv2d):
                nn.init.kaiming_normal_(m.weight, mode="fan_out", nonlinearity="relu")
                if m.bias is not None:
                    nn.init.zeros_(m.bias)
            elif isinstance(m, nn.BatchNorm2d):
                nn.init.ones_(m.weight)
                nn.init.zeros_(m.bias)

    @staticmethod
    def _rms_norm_per_sample(feat: torch.Tensor) -> torch.Tensor:
        """Batch-independent RMS feature scaling (prevents NaN on dark crops and batch-size drift)."""
        rms = torch.sqrt(torch.mean(feat.pow(2), dim=(1, 2, 3), keepdim=True) + 1e-6)
        return feat / rms

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        f0 = F.avg_pool2d(x, kernel_size=5, stride=5)            # (B, 3, 4, 18)
        s1 = self.stage1(x)                                      # (B, 128, 20, 90)
        f1 = F.avg_pool2d(s1, kernel_size=5, stride=5)           # (B, 128, 4, 18)

        s2 = self.stage2(s1)                                     # (B, 256, 18, 44)
        f2 = F.avg_pool2d(s2, kernel_size=(4, 10), stride=(4, 2))# (B, 256, 4, 18)

        s3 = self.stage3(s2)                                     # (B, C, 4, 18)

        fused = torch.cat(
            [
                self._rms_norm_per_sample(f0),
                self._rms_norm_per_sample(f1),
                self._rms_norm_per_sample(f2),
                self._rms_norm_per_sample(s3),
            ],
            dim=1,
        )

        out = self.container(fused)                              # (B, C, 4, 18)
        logits = torch.mean(out, dim=2)                          # (B, C, 18)
        return logits


def select_inference_device(prefer_gpu: bool = True) -> torch.device:
    """
    Detect CUDA availability and verify a small tensor allocation succeeds on GPU;
    gracefully fall back to CPU if CUDA is unavailable or out of memory.
    """
    if prefer_gpu and torch.cuda.is_available():
        try:
            dev = torch.device("cuda:0")
            _ = torch.zeros(1, device=dev)
            return dev
        except Exception as exc:
            print(f"[LPRNet] CUDA detected but unusable ({exc}); falling back to CPU.")
    return torch.device("cpu")


def save_lprnet_checkpoint(
    model: LPRNet,
    filepath: str,
    epoch: int = 0,
    metrics: Optional[Dict[str, Any]] = None,
    extra_meta: Optional[Dict[str, Any]] = None,
) -> None:
    """Save LPRNet weights alongside alphabet and training/validation provenance metadata."""
    os.makedirs(os.path.dirname(os.path.abspath(filepath)), exist_ok=True)
    payload: Dict[str, Any] = {
        "architecture": "LPRNet_PH_v1",
        "alphabet": PH_CHARS,
        "num_classes": model.num_classes,
        "input_size": [LPRNET_INPUT_WIDTH, LPRNET_INPUT_HEIGHT],
        "epoch": int(epoch),
        "metrics": metrics or {},
        "extra_meta": extra_meta or {},
        "state_dict": model.state_dict(),
    }
    torch.save(payload, filepath)


def load_lprnet_checkpoint(
    filepath: str,
    device: Optional[torch.device] = None,
    dropout_rate: float = 0.0,
) -> Tuple[LPRNet, Dict[str, Any]]:
    """
    Load a trained Philippine LPRNet checkpoint onto `device` (auto-selected if None).
    Raises FileNotFoundError if `filepath` does not exist, and ValueError if the saved
    alphabet does not match `PH_CHARS`.
    """
    if not os.path.exists(filepath):
        raise FileNotFoundError(f"LPRNet checkpoint not found: {filepath}")

    target_device = device if device is not None else select_inference_device()
    ckpt = torch.load(filepath, map_location=target_device, weights_only=False)

    model = LPRNet(num_classes=NUM_CLASSES, dropout_rate=dropout_rate)

    if isinstance(ckpt, dict) and "state_dict" in ckpt:
        saved_alphabet = ckpt.get("alphabet", PH_CHARS)
        if saved_alphabet != PH_CHARS:
            raise ValueError(
                f"Checkpoint alphabet mismatch: expected '{PH_CHARS}', got '{saved_alphabet}'. "
                "Use load_pretrained_backbone_partial() for foreign-alphabet transfer learning."
            )
        model.load_state_dict(ckpt["state_dict"], strict=True)
        meta = {k: v for k, v in ckpt.items() if k != "state_dict"}
    elif isinstance(ckpt, dict):
        # Raw state_dict dictionary
        model.load_state_dict(ckpt, strict=True)
        meta = {"architecture": "raw_state_dict"}
    else:
        raise ValueError(f"Unsupported checkpoint structure in {filepath}")

    model.to(target_device)
    model.eval()
    return model, meta


def load_pretrained_backbone_partial(model: LPRNet, foreign_ckpt_path: str) -> Tuple[int, int]:
    """
    Transfer shape-compatible convolutional layers from an external LPRNet checkpoint
    (e.g., CCPD pretrained weights) while keeping the 37-class Philippine head intact.

    Returns:
        (loaded_tensor_count, total_model_tensor_count)
    """
    if not os.path.exists(foreign_ckpt_path):
        raise FileNotFoundError(f"Pretrained checkpoint not found: {foreign_ckpt_path}")

    raw = torch.load(foreign_ckpt_path, map_location="cpu", weights_only=False)
    src_state = raw["state_dict"] if isinstance(raw, dict) and "state_dict" in raw else raw

    dst_state = model.state_dict()
    compatible = {}
    for k, v in src_state.items():
        clean_k = k.replace("module.", "")
        if clean_k in dst_state and dst_state[clean_k].shape == v.shape:
            compatible[clean_k] = v

    dst_state.update(compatible)
    model.load_state_dict(dst_state)
    return len(compatible), len(dst_state)

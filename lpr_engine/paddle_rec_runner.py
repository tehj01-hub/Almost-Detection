"""
Lightweight native Paddle Inference runner for PP-OCRv6_medium_rec.

Loads the locally cached `.paddle_cache/.paddlex/official_models/PP-OCRv6_medium_rec`
recognition model directly via `paddle.inference` without requiring `paddlex` or
network model checks.
"""

import math
import os
import threading
from typing import Dict, List

import cv2
import numpy as np
import yaml


class LocalPPOCRv6Recognizer:
    """Thread-safe recognition-only runner compatible with QuadReaderEnsemble."""

    plate_recognition_only: bool = True

    def __init__(self, model_dir: str, use_gpu: bool = False, cpu_threads: int = 2) -> None:
        if not model_dir or not os.path.isdir(model_dir):
            raise FileNotFoundError(f"PP-OCRv6 model directory not found: {model_dir}")

        json_path = os.path.join(model_dir, "inference.json")
        params_path = os.path.join(model_dir, "inference.pdiparams")
        yml_path = os.path.join(model_dir, "inference.yml")
        for p in (json_path, params_path, yml_path):
            if not os.path.isfile(p):
                raise FileNotFoundError(f"Missing PP-OCRv6 model artifact: {p}")

        import paddle
        import paddle.inference as paddle_infer

        with open(yml_path, "r", encoding="utf-8") as f:
            cfg_yml = yaml.safe_load(f) or {}

        raw_dict = cfg_yml.get("PostProcess", {}).get("character_dict", [])
        if not raw_dict:
            raise ValueError(f"Empty character_dict in {yml_path}")
        self.char_list: List[str] = ["blank"] + [str(c) for c in raw_dict] + [" "]

        cfg = paddle_infer.Config(json_path, params_path)
        if hasattr(cfg, "disable_glog_info"):
            cfg.disable_glog_info()

        if use_gpu and paddle.is_compiled_with_cuda():
            cfg.enable_use_gpu(256, 0)
        else:
            cfg.disable_gpu()
            cfg.set_cpu_math_library_num_threads(max(1, int(cpu_threads)))
            if hasattr(cfg, "disable_mkldnn"):
                cfg.disable_mkldnn()

        self._lock = threading.Lock()
        self._predictor = paddle_infer.create_predictor(cfg)
        self._input_name = self._predictor.get_input_names()[0]
        self._output_name = self._predictor.get_output_names()[0]

    def predict(self, bgr_crop: np.ndarray) -> List[Dict[str, object]]:
        """Run CTC sequence recognition on a cropped license plate image."""
        if bgr_crop is None or bgr_crop.size == 0:
            return [{"rec_text": "", "rec_score": 0.0}]

        if bgr_crop.ndim == 2:
            img = cv2.cvtColor(bgr_crop, cv2.COLOR_GRAY2BGR)
        else:
            img = bgr_crop[:, :, :3]

        h, w = img.shape[:2]
        if h < 4 or w < 4:
            return [{"rec_text": "", "rec_score": 0.0}]

        img_h = 48
        ratio = w / float(h)
        resized_w = min(3200, max(48, int(math.ceil(img_h * ratio))))
        padded_w = max(320, resized_w)

        resized = cv2.resize(img, (resized_w, img_h), interpolation=cv2.INTER_LINEAR)
        norm = resized.astype(np.float32).transpose((2, 0, 1)) / 255.0
        norm = (norm - 0.5) / 0.5

        tensor = np.zeros((1, 3, img_h, padded_w), dtype=np.float32)
        tensor[0, :, :, :resized_w] = norm

        with self._lock:
            in_handle = self._predictor.get_input_handle(self._input_name)
            out_handle = self._predictor.get_output_handle(self._output_name)
            in_handle.copy_from_cpu(tensor)
            self._predictor.run()
            probs = out_handle.copy_to_cpu()[0]

        idxs = probs.argmax(axis=-1)
        chars: List[str] = []
        scores: List[float] = []
        prev = -1
        num_classes = len(self.char_list)

        for t, idx in enumerate(idxs):
            idx_int = int(idx)
            if idx_int != prev and idx_int != 0 and idx_int < num_classes:
                chars.append(self.char_list[idx_int])
                scores.append(float(probs[t, idx_int]))
            prev = idx_int

        text = "".join(chars).strip()
        avg_score = float(sum(scores) / len(scores)) if scores else 0.0
        return [{"rec_text": text, "rec_score": avg_score}]

"""
GTX / GPU-Accelerated Character Extraction & Multi-View Enhancement Pipeline.

Supports BOTH:
1. Native PyTorch CUDA (`cuda:0`) when CUDA PyTorch is installed.
2. NVIDIA GeForce GTX / Discrete & Integrated GPUs via OpenVINO `'GPU'` (OpenCL) +
   OpenCV OpenCL (`cv2.ocl`), compiling EasyOCR's FP32 CRAFT character detector and
   FP32 VGG feature-extraction CNN directly onto the GTX GPU with persistent kernel caching.

Capabilities:
- Spatially isolates the license plate character band via GPU CRAFT detection while
  trimming thin outer plate-holder frame borders and excluding small header/footer text
  (e.g., 'REGISTERED', 'PILIPINAS', corner stickers).
- Generates 3 complementary GPU-enhanced character views (aspect-calibrated sharp view,
  31-tap separable illumination-flattened de-glare view, and stroke de-shear / polarity
  contrast view) framed on EasyOCR's natural 64px padded scene-text canvas.
"""

import os
import threading
from typing import Any, List, Optional, Tuple

import cv2
import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F

from .preprocessing import PlatePreprocessor


class _CraftNCHW(nn.Module):
    """Exports EasyOCR CRAFT up to NCHW logits (avoiding trailing NHWC permute kernel limits)."""

    def __init__(self, craft_module: nn.Module) -> None:
        super().__init__()
        self.c = craft_module

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        sources = self.c.basenet(x)
        y = torch.cat([sources[0], sources[1]], dim=1)
        y = self.c.upconv1(y)

        y = F.interpolate(y, size=sources[2].shape[2:], mode="bilinear", align_corners=False)
        y = torch.cat([y, sources[2]], dim=1)
        y = self.c.upconv2(y)

        y = F.interpolate(y, size=sources[3].shape[2:], mode="bilinear", align_corners=False)
        y = torch.cat([y, sources[3]], dim=1)
        y = self.c.upconv3(y)

        y = F.interpolate(y, size=sources[4].shape[2:], mode="bilinear", align_corners=False)
        y = torch.cat([y, sources[4]], dim=1)
        feature = self.c.upconv4(y)
        return self.c.conv_cls(feature)


class _GTXOpenVINOCraftDetector(nn.Module):
    """Thread-safe drop-in replacement for `easyocr.Reader.detector` running on GTX via OpenVINO GPU."""

    STATIC_H = 128
    STATIC_W = 384

    def __init__(self, orig_detector: nn.Module, compiled_model: Any) -> None:
        super().__init__()
        self.orig_detector = orig_detector
        self.compiled_model = compiled_model
        self._lock = threading.Lock()

    def forward(self, x: torch.Tensor) -> Tuple[torch.Tensor, Optional[torch.Tensor]]:
        b, c, h_in, w_in = x.shape
        if b != 1 or c != 3:
            return self.orig_detector(x)

        try:
            need_resize = (h_in != self.STATIC_H) or (w_in != self.STATIC_W)
            x_gpu = (
                F.interpolate(x, size=(self.STATIC_H, self.STATIC_W), mode="bilinear", align_corners=False)
                if need_resize
                else x
            )
            x_np = np.ascontiguousarray(x_gpu.detach().cpu().numpy(), dtype=np.float32)

            with self._lock:
                y_nchw_np = self.compiled_model(x_np)[0]

            y_nchw = torch.from_numpy(np.array(y_nchw_np, copy=True))
            target_h, target_w = max(1, h_in // 2), max(1, w_in // 2)
            if y_nchw.shape[2] != target_h or y_nchw.shape[3] != target_w:
                y_nchw = F.interpolate(y_nchw, size=(target_h, target_w), mode="bilinear", align_corners=False)

            return y_nchw.permute(0, 2, 3, 1).contiguous(), None
        except Exception:
            return self.orig_detector(x)


class _GTXOpenVINOVGGExtractor(nn.Module):
    """Thread-safe drop-in replacement for `recognizer.FeatureExtraction` running on GTX via OpenVINO GPU."""

    STATIC_H = 64
    STATIC_W = 512

    def __init__(self, orig_fe: nn.Module, compiled_model: Any) -> None:
        super().__init__()
        self.orig_fe = orig_fe
        self.compiled_model = compiled_model
        self._lock = threading.Lock()

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        b, c, h_in, w_in = x.shape
        if b != 1 or c != 1 or h_in != self.STATIC_H or w_in > self.STATIC_W or w_in < 12:
            return self.orig_fe(x)

        try:
            out_t = max(1, (w_in // 4) - 1)
            if w_in < self.STATIC_W:
                x_pad = F.pad(x, (0, self.STATIC_W - w_in), mode="replicate")
            else:
                x_pad = x

            x_np = np.ascontiguousarray(x_pad.detach().cpu().numpy(), dtype=np.float32)
            with self._lock:
                feat_np = self.compiled_model(x_np)[0]

            feat = torch.from_numpy(np.array(feat_np[:, :, :, :out_t], copy=True))
            return feat
        except Exception:
            return self.orig_fe(x)


def detect_openvino_gpu(cache_dir: Optional[str] = None) -> Tuple[bool, str]:
    """Check whether OpenVINO sees a discrete or integrated GPU (e.g. NVIDIA GeForce GTX 1650 Ti)."""
    try:
        import openvino as ov

        core = ov.Core()
        if cache_dir:
            os.makedirs(cache_dir, exist_ok=True)
            core.set_property({"CACHE_DIR": cache_dir})
        devices = core.available_devices
        if "GPU" in devices:
            full_name = str(core.get_property("GPU", "FULL_DEVICE_NAME")).strip()
            return True, full_name or "OpenVINO GPU"
    except Exception:
        pass
    return False, ""


class GPUCharacterExtractor:
    """GTX GPU-accelerated plate character region localizer and multi-view OCR enhancer."""

    ALLOWLIST = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-"

    def __init__(
        self,
        device: Optional[torch.device] = None,
        target_height: int = 128,
        cache_dir: Optional[str] = None,
    ) -> None:
        project_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        self.cache_dir = cache_dir or os.path.join(project_root, ".openvino_cache")

        if device is None:
            self.device = torch.device("cuda:0" if torch.cuda.is_available() else "cpu")
        else:
            self.device = device

        self.target_height = int(target_height)
        self._gauss_small_1d: Optional[torch.Tensor] = None
        self._gauss_large_1d: Optional[torch.Tensor] = None

        # Enable OpenCV OpenCL transparent API on NVIDIA GTX GPU when available
        self.opencl_active = False
        try:
            if cv2.ocl.haveOpenCL():
                cv2.ocl.setUseOpenCL(True)
                self.opencl_active = bool(cv2.ocl.useOpenCL())
        except Exception:
            self.opencl_active = False

        self.is_openvino_gpu, self.openvino_gpu_name = (
            (False, "") if self.is_cuda else detect_openvino_gpu(self.cache_dir)
        )

    @property
    def is_cuda(self) -> bool:
        return self.device.type == "cuda" and torch.cuda.is_available()

    @property
    def is_gpu_available(self) -> bool:
        return bool(self.is_cuda or self.is_openvino_gpu)

    @property
    def gpu_name(self) -> str:
        if self.is_cuda:
            try:
                return str(torch.cuda.get_device_name(0))
            except Exception:
                return "NVIDIA CUDA GPU"
        if self.is_openvino_gpu:
            return self.openvino_gpu_name
        return "CPU"

    def _refresh_device(self) -> torch.device:
        desired = torch.device("cuda:0" if torch.cuda.is_available() else "cpu")
        if desired != self.device:
            self.device = desired
            self._gauss_small_1d = None
            self._gauss_large_1d = None
        return self.device

    def _get_gaussian_1d(self, ksize: int, sigma: float) -> torch.Tensor:
        coords = torch.arange(ksize, dtype=torch.float32, device=self.device) - (ksize - 1) / 2.0
        g1d = torch.exp(-(coords ** 2) / (2.0 * sigma * sigma))
        return (g1d / g1d.sum()).view(1, 1, 1, ksize)

    def _ensure_kernels(self) -> Tuple[torch.Tensor, torch.Tensor]:
        self._refresh_device()
        if self._gauss_small_1d is None:
            self._gauss_small_1d = self._get_gaussian_1d(5, 1.1)
        if self._gauss_large_1d is None:
            self._gauss_large_1d = self._get_gaussian_1d(31, 9.0)
        return self._gauss_small_1d, self._gauss_large_1d

    @staticmethod
    def _sep_blur(x: torch.Tensor, k_horiz: torch.Tensor) -> torch.Tensor:
        """Separable 2D Gaussian convolution (15x faster than dense 2D conv, identical output)."""
        pad = k_horiz.shape[-1] // 2
        k_vert = k_horiz.transpose(2, 3)
        tmp = F.conv2d(F.pad(x, (pad, pad, 0, 0), mode="replicate"), k_horiz)
        return F.conv2d(F.pad(tmp, (0, 0, pad, pad), mode="replicate"), k_vert)

    def accelerate_easyocr_reader(self, easyocr_reader: Any) -> bool:
        """
        Compile EasyOCR's FP32 CRAFT text detector and VGG character feature extractor
        onto the discrete GTX GPU via OpenVINO GPU when PyTorch is CPU-only.
        """
        if easyocr_reader is None:
            return False
        if getattr(easyocr_reader, "gtx_accelerated", False):
            return True
        if self.is_cuda:
            easyocr_reader.gtx_accelerated = True
            easyocr_reader.gtx_device_name = self.gpu_name
            return True
        if not self.is_openvino_gpu:
            return False

        try:
            import openvino as ov

            core = ov.Core()
            os.makedirs(self.cache_dir, exist_ok=True)
            core.set_property({"CACHE_DIR": self.cache_dir})
            compile_cfg = {"INFERENCE_PRECISION_HINT": "f32", "PERFORMANCE_HINT": "LATENCY"}

            accelerated_any = False

            # 1. Compile CRAFT Character Detector onto GTX GPU
            det = getattr(easyocr_reader, "detector", None)
            if isinstance(det, nn.Module) and not isinstance(det, _GTXOpenVINOCraftDetector):
                raw_det = det.module if hasattr(det, "module") else det
                raw_det.eval()
                craft_nchw = _CraftNCHW(raw_det).eval()
                ex_det = torch.zeros(
                    1, 3, _GTXOpenVINOCraftDetector.STATIC_H, _GTXOpenVINOCraftDetector.STATIC_W, dtype=torch.float32
                )
                ov_det = ov.convert_model(
                    craft_nchw,
                    example_input=ex_det,
                    input=[(1, 3, _GTXOpenVINOCraftDetector.STATIC_H, _GTXOpenVINOCraftDetector.STATIC_W)],
                )
                comp_det = core.compile_model(ov_det, "GPU", compile_cfg)
                easyocr_reader.detector = _GTXOpenVINOCraftDetector(det, comp_det)
                accelerated_any = True

            # 2. Compile Recognizer VGG FeatureExtraction CNN onto GTX GPU
            rec = getattr(easyocr_reader, "recognizer", None)
            raw_rec = rec.module if hasattr(rec, "module") else rec
            fe = getattr(raw_rec, "FeatureExtraction", None) if raw_rec is not None else None
            if isinstance(fe, nn.Module) and not isinstance(fe, _GTXOpenVINOVGGExtractor):
                fe.eval()
                ex_fe = torch.zeros(
                    1, 1, _GTXOpenVINOVGGExtractor.STATIC_H, _GTXOpenVINOVGGExtractor.STATIC_W, dtype=torch.float32
                )
                ov_fe = ov.convert_model(
                    fe,
                    example_input=ex_fe,
                    input=[(1, 1, _GTXOpenVINOVGGExtractor.STATIC_H, _GTXOpenVINOVGGExtractor.STATIC_W)],
                )
                comp_fe = core.compile_model(ov_fe, "GPU", compile_cfg)
                raw_rec.FeatureExtraction = _GTXOpenVINOVGGExtractor(fe, comp_fe)
                accelerated_any = True

            if accelerated_any:
                easyocr_reader.gtx_accelerated = True
                easyocr_reader.gtx_device_name = self.openvino_gpu_name
            return accelerated_any
        except Exception:
            return False

    @staticmethod
    def _estimate_shear(gray_u8: np.ndarray) -> float:
        """Estimate horizontal character slant ratio dx/dy in [-0.45, 0.45] for foreshortened plates."""
        if gray_u8 is None or gray_u8.size == 0:
            return 0.0
        gx = cv2.Sobel(gray_u8, cv2.CV_32F, 1, 0, ksize=3)
        gy = cv2.Sobel(gray_u8, cv2.CV_32F, 0, 1, ksize=3)
        mag = np.hypot(gx, gy)
        thresh = float(np.percentile(mag, 80))
        mask = (mag > max(12.0, thresh)) & (np.abs(gx) > np.abs(gy) * 1.2)
        if int(np.count_nonzero(mask)) < 30:
            return 0.0
        ratios = -gy[mask] / (gx[mask] + 1e-6)
        ratios = ratios[(ratios > -0.50) & (ratios < 0.50)]
        return float(np.median(ratios)) if len(ratios) >= 20 else 0.0

    @staticmethod
    def _frame_on_ocr_canvas(glyph_u8: np.ndarray, width_scale: float = 1.0) -> np.ndarray:
        """
        Place enhanced character band onto EasyOCR's optimal 64px-tall canvas with clean
        top/bottom (11px) and left/right (16px) background margin so glyphs are never clipped
        by VGG max-pooling or CTC boundary states.
        """
        gh, gw = glyph_u8.shape[:2]
        glyph_target_h = 42
        scaled_w = min(480, max(48, int(round(gw * (glyph_target_h / float(max(1, gh))) * width_scale))))
        interp = cv2.INTER_CUBIC if scaled_w >= gw else cv2.INTER_AREA
        resized = cv2.resize(glyph_u8, (scaled_w, glyph_target_h), interpolation=interp)
        bg_val = int(np.percentile(resized, 85))
        return cv2.copyMakeBorder(resized, 11, 11, 16, 16, cv2.BORDER_CONSTANT, value=bg_val)

    def enhance_variants_gpu(self, bgr_crop: np.ndarray, target_h: Optional[int] = None) -> List[np.ndarray]:
        """
        Produce 3 complementary GPU-enhanced grayscale character views framed for CTC recognition:
          1. Aspect-calibrated (1.40x width) percentile auto-levels + unsharp mask (resolves D/0, B/8, Z/2).
          2. 31-tap separable illumination-leveled view (removes headlight glare & uneven shadows).
          3. Slant de-sheared + wide aspect view (for angled/foreshortened plates) OR polarity-inverted
             high-contrast S-curve view (for dark/tinted plates).
        """
        if bgr_crop is None or bgr_crop.size == 0:
            return []

        dev = self._refresh_device()
        k_small, k_large = self._ensure_kernels()

        trimmed = PlatePreprocessor.trim_holder_borders(bgr_crop) if bgr_crop.ndim == 3 else bgr_crop
        h, w = trimmed.shape[:2]
        out_h = target_h or (self.target_height if self.is_gpu_available else 96)
        scale = out_h / float(max(1, h))
        out_w = min(1536, max(48, int(round(w * scale))))

        with torch.no_grad():
            if trimmed.ndim == 3:
                t_bgr = torch.from_numpy(np.ascontiguousarray(trimmed)).to(device=dev, dtype=torch.float32)
                gray = (0.114 * t_bgr[:, :, 0] + 0.587 * t_bgr[:, :, 1] + 0.299 * t_bgr[:, :, 2]) / 255.0
            else:
                gray = torch.from_numpy(np.ascontiguousarray(trimmed)).to(device=dev, dtype=torch.float32) / 255.0

            gray_4d = gray.unsqueeze(0).unsqueeze(0)
            up = F.interpolate(gray_4d, size=(out_h, out_w), mode="bicubic", align_corners=False).clamp(0.0, 1.0)

            # Base 1: Percentile auto-levels + crisp unsharp mask
            flat = up.view(-1)
            p_low = torch.quantile(flat, 0.02)
            p_high = torch.quantile(flat, 0.98)
            v1_base = ((up - p_low) / torch.clamp(p_high - p_low, min=0.08)).clamp(0.0, 1.0)
            blur_v1 = self._sep_blur(v1_base, k_small)
            v1 = (1.48 * v1_base - 0.48 * blur_v1).clamp(0.0, 1.0)

            # Base 2: Separable 31-tap illumination division (de-glare / de-shadow) + mild sharpen
            illum = self._sep_blur(up, k_large)
            leveled = (up / torch.clamp(illum, min=0.12)) * float(illum.mean().clamp(0.35, 0.75).item())
            leveled = leveled.clamp(0.0, 1.0)
            l_flat = leveled.view(-1)
            lp_lo = torch.quantile(l_flat, 0.02)
            lp_hi = torch.quantile(l_flat, 0.98)
            leveled = ((leveled - lp_lo) / torch.clamp(lp_hi - lp_lo, min=0.08)).clamp(0.0, 1.0)
            blur_v2 = self._sep_blur(leveled, k_small)
            v2 = (1.35 * leveled - 0.35 * blur_v2).clamp(0.0, 1.0)

            # Base 3: Polarity check + S-curve contrast separation
            ch0, ch1 = int(out_h * 0.2), int(out_h * 0.8)
            cw0, cw1 = int(out_w * 0.1), int(out_w * 0.9)
            center_mean = float(v1_base[0, 0, ch0:max(ch0 + 1, ch1), cw0:max(cw0 + 1, cw1)].mean().item())
            base_v3 = (1.0 - v2) if center_mean < 0.42 else v2
            v3 = torch.sigmoid((base_v3 - 0.5) * 6.0)
            v3 = ((v3 - v3.min()) / torch.clamp(v3.max() - v3.min(), min=1e-4)).clamp(0.0, 1.0)

            stacked = torch.cat([v1, v2, v3], dim=0).squeeze(1)
            raw_u8 = (stacked * 255.0).round().to(torch.uint8).cpu().numpy()

        # When target_h is explicitly requested (e.g. Paddle BGR crop), return direct enhanced bands
        if target_h is not None:
            return [raw_u8[0], raw_u8[1], raw_u8[2]]

        # Frame onto 64px OCR canvas with calibrated horizontal aspect scales
        c_v1 = self._frame_on_ocr_canvas(raw_u8[0], width_scale=1.40)
        c_v2 = self._frame_on_ocr_canvas(raw_u8[1], width_scale=1.18)

        shear = self._estimate_shear(raw_u8[0])
        if abs(shear) >= 0.10 and center_mean >= 0.42:
            M = np.float32([[1.0, -shear, shear * out_h * 0.5], [0.0, 1.0, 0.0]])
            desheared = cv2.warpAffine(
                raw_u8[0], M, (out_w, out_h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE
            )
            c_v3 = self._frame_on_ocr_canvas(desheared, width_scale=1.75)
        else:
            c_v3 = self._frame_on_ocr_canvas(raw_u8[2], width_scale=1.28)

        return [c_v1, c_v2, c_v3]

    def enhance_for_paddle_bgr(self, bgr_crop: np.ndarray) -> np.ndarray:
        """Return an illumination-leveled & sharpened 3-channel BGR crop for PP-OCRv6."""
        variants = self.enhance_variants_gpu(bgr_crop, target_h=96)
        if len(variants) >= 2:
            return cv2.cvtColor(variants[1], cv2.COLOR_GRAY2BGR)
        return bgr_crop

    def localize_character_band(self, easyocr_reader: Any, bgr_crop: np.ndarray) -> Optional[np.ndarray]:
        """
        Use EasyOCR's GTX-accelerated CRAFT detector (`easyocr.detect`) to isolate the main
        character band inside `bgr_crop`, excluding plastic plate-holder borders, bolts, and
        small registration/dealer header text.
        """
        if easyocr_reader is None or not hasattr(easyocr_reader, "detect"):
            return None
        if bgr_crop is None or bgr_crop.size == 0:
            return None

        h, w = bgr_crop.shape[:2]
        if h < 18 or w < 45:
            return None

        try:
            work = PlatePreprocessor.trim_holder_borders(bgr_crop) if bgr_crop.ndim == 3 else bgr_crop
            wh, ww = work.shape[:2]
            det_h = 128
            scale = det_h / float(max(1, wh))
            det_w = min(960, max(64, int(round(ww * scale))))
            det_img = cv2.resize(work, (det_w, det_h), interpolation=cv2.INTER_CUBIC)

            horiz_list, free_list = easyocr_reader.detect(
                det_img,
                min_size=10,
                text_threshold=0.58,
                low_text=0.35,
                link_threshold=0.35,
                add_margin=0.0,
                canvas_size=max(384, det_w),
                mag_ratio=1.0,
            )

            boxes: List[Tuple[float, float, float, float]] = []
            if horiz_list and len(horiz_list) > 0:
                for b in horiz_list[0]:
                    if len(b) >= 4:
                        x1, x2, y1, y2 = float(b[0]), float(b[1]), float(b[2]), float(b[3])
                        if x2 > x1 + 6 and y2 > y1 + 12:
                            boxes.append((x1, y1, x2, y2))

            if free_list and len(free_list) > 0:
                for poly in free_list[0]:
                    pts = np.asarray(poly, dtype=np.float32)
                    if pts.ndim == 2 and pts.shape[0] >= 4:
                        x1, y1 = float(pts[:, 0].min()), float(pts[:, 1].min())
                        x2, y2 = float(pts[:, 0].max()), float(pts[:, 1].max())
                        if x2 > x1 + 6 and y2 > y1 + 12:
                            boxes.append((x1, y1, x2, y2))

            if not boxes:
                return None

            # Keep boxes belonging to the dominant character height (drops 'REGISTERED' / 'PILIPINAS' / stickers)
            max_bh = max(b[3] - b[1] for b in boxes)
            if max_bh < 34.0:
                return None
            main_boxes = [b for b in boxes if (b[3] - b[1]) >= 0.52 * max_bh]
            if not main_boxes:
                main_boxes = boxes

            bx1 = max(0.0, min(b[0] for b in main_boxes))
            by1 = max(0.0, min(b[1] for b in main_boxes))
            bx2 = min(float(det_w), max(b[2] for b in main_boxes))
            by2 = min(float(det_h), max(b[3] for b in main_boxes))
            span_w = bx2 - bx1
            span_h = by2 - by1

            # Shave thin left plate-border frame line when CRAFT touches the left crop edge
            if bx1 < det_w * 0.06:
                bx1 += span_w * 0.030

            inv_scale_x = ww / float(det_w)
            inv_scale_y = wh / float(det_h)
            pad_y = max(1, int(round(span_h * inv_scale_y * 0.06)))

            cx1 = max(0, int(round(bx1 * inv_scale_x)))
            cy1 = max(0, int(round(by1 * inv_scale_y)) - pad_y)
            cx2 = min(ww, int(round(bx2 * inv_scale_x)))
            cy2 = min(wh, int(round(by2 * inv_scale_y)) + pad_y)

            if (cx2 - cx1) < 40 or (cy2 - cy1) < 14:
                return None

            return work[cy1:cy2, cx1:cx2]
        except Exception:
            return None

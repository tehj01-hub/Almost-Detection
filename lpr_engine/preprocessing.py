"""
Conditional Quality-Aware License Plate Preprocessing & Ablation Pipeline.

Evaluates image sharpness, brightness, contrast, noise, and skew angle first,
applying ONLY the operations justified by the crop's measured condition rather
than unconditionally degrading clean high-resolution crops.
"""

from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np

from .lprnet_model import LPRNET_INPUT_HEIGHT, LPRNET_INPUT_WIDTH


@dataclass
class CropQualityMetrics:
    """Objective quality measurements computed on a raw BGR plate crop."""
    width: int
    height: int
    blur_score: float           # Variance of Laplacian on standardized 64px-high grayscale
    mean_brightness: float      # [0, 255]
    contrast_std: float         # Grayscale standard deviation
    noise_sigma: float          # Estimated high-frequency noise level
    skew_angle_deg: float       # Estimated horizontal tilt in degrees
    is_usable: bool             # False if too tiny or severely motion-blurred
    condition_tags: List[str] = field(default_factory=list)


class PlatePreprocessor:
    """
    Consistent preprocessing pipeline shared across LPRNet training, validation, and live inference.

    Supported `mode` values for empirical comparison / ablation:
        - "raw_resize":        Direct stretch resize to (94, 24) without aspect padding or enhancement.
        - "aspect_pad_only":   Aspect-ratio-preserving resize + replicate border padding to (94, 24).
        - "conditional_smart": Quality-gated conditional deskew, gamma, CLAHE, bilateral denoise,
                               mild sharpen (only when needed) + aspect-ratio-preserving resize.
        - "all_forced":        Applies CLAHE + denoise + unsharp mask unconditionally (for ablation comparison).
    """

    VALID_MODES = ("raw_resize", "aspect_pad_only", "conditional_smart", "all_forced")

    def __init__(
        self,
        mode: str = "conditional_smart",
        min_crop_width: int = 42,
        min_crop_height: int = 14,
        min_blur_score: float = 22.0,
        low_light_threshold: float = 70.0,
        glare_threshold: float = 210.0,
        low_contrast_threshold: float = 38.0,
        high_noise_threshold: float = 11.0,
        min_deskew_angle_deg: float = 5.5,
    ) -> None:
        if mode not in self.VALID_MODES:
            raise ValueError(f"Unsupported preprocessing mode '{mode}'. Choose from {self.VALID_MODES}.")
        self.mode = mode
        self.min_crop_width = min_crop_width
        self.min_crop_height = min_crop_height
        self.min_blur_score = min_blur_score
        self.low_light_threshold = low_light_threshold
        self.glare_threshold = glare_threshold
        self.low_contrast_threshold = low_contrast_threshold
        self.high_noise_threshold = high_noise_threshold
        self.min_deskew_angle_deg = min_deskew_angle_deg

    def assess_quality(self, bgr_crop: np.ndarray) -> CropQualityMetrics:
        """Measure blur, brightness, contrast, noise, and tilt angle of a BGR plate crop."""
        if bgr_crop is None or bgr_crop.size == 0 or bgr_crop.ndim < 2:
            return CropQualityMetrics(0, 0, 0.0, 0.0, 0.0, 0.0, 0.0, False, ["empty"])

        h, w = bgr_crop.shape[:2]
        gray = cv2.cvtColor(bgr_crop, cv2.COLOR_BGR2GRAY) if bgr_crop.ndim == 3 else bgr_crop

        # Standardize height to 48px before Laplacian so blur_score is resolution-consistent
        norm_h = 48
        norm_w = max(16, int(round(w * (norm_h / max(1.0, float(h))))))
        gray_std = cv2.resize(gray, (norm_w, norm_h), interpolation=cv2.INTER_LINEAR)

        blur_score = float(cv2.Laplacian(gray_std, cv2.CV_64F).var())
        mean_brightness = float(np.mean(gray_std))
        contrast_std = float(np.std(gray_std))

        # Fast noise estimate via median-residual MAD
        med = cv2.medianBlur(gray_std, 3)
        noise_sigma = float(np.median(np.abs(gray_std.astype(np.float32) - med.astype(np.float32))) * 1.4826)

        skew_angle = self._estimate_skew_angle(gray_std)

        tags: List[str] = []
        if mean_brightness < 55.0:
            tags.append("nighttime")
        elif mean_brightness < self.low_light_threshold:
            tags.append("low_light")
        else:
            tags.append("daytime")

        if blur_score < 50.0:
            tags.append("blurry")
        if abs(skew_angle) >= self.min_deskew_angle_deg:
            tags.append("angled")

        is_usable = (w >= self.min_crop_width) and (h >= self.min_crop_height) and (blur_score >= self.min_blur_score)

        return CropQualityMetrics(
            width=w,
            height=h,
            blur_score=round(blur_score, 2),
            mean_brightness=round(mean_brightness, 2),
            contrast_std=round(contrast_std, 2),
            noise_sigma=round(noise_sigma, 2),
            skew_angle_deg=round(skew_angle, 2),
            is_usable=is_usable,
            condition_tags=tags,
        )

    @staticmethod
    def _estimate_skew_angle(gray: np.ndarray) -> float:
        """Estimate plate tilt in degrees [-25, 25] using Canny + Hough line segments."""
        edges = cv2.Canny(gray, 50, 150)
        lines = cv2.HoughLinesP(
            edges,
            rho=1.0,
            theta=np.pi / 180.0,
            threshold=max(15, gray.shape[1] // 4),
            minLineLength=max(14, gray.shape[1] // 3),
            maxLineGap=8,
        )
        if lines is None or len(lines) == 0:
            return 0.0

        angles: List[float] = []
        for line in lines:
            x1, y1, x2, y2 = line[0]
            dx = float(x2 - x1)
            dy = float(y2 - y1)
            if abs(dx) < 6.0:
                continue
            ang = float(np.degrees(np.arctan2(dy, dx)))
            if -25.0 <= ang <= 25.0:
                angles.append(ang)

        if not angles:
            return 0.0
        return float(np.median(angles))

    @staticmethod
    def trim_holder_borders(bgr: np.ndarray) -> np.ndarray:
        """Trim dark top/bottom plastic plate-frame bands (e.g., dealer/LTO holders) when present."""
        h, w = bgr.shape[:2]
        if h < 20 or w < 45:
            return bgr
        gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
        row_means = np.mean(gray, axis=1)
        center_mean = float(np.mean(row_means[int(h * 0.25): int(h * 0.75)]))
        if center_mean < 60.0:
            return bgr  # Dark plate or nighttime crop; avoid trimming characters

        top = 0
        max_top = int(h * 0.14)
        while top < max_top and row_means[top] < center_mean * 0.48:
            top += 1

        bottom = h
        min_bottom = h - int(h * 0.14)
        while bottom > min_bottom and row_means[bottom - 1] < center_mean * 0.48:
            bottom -= 1

        if bottom - top >= int(h * 0.72):
            return bgr[top:bottom, :]
        return bgr

    @staticmethod
    def perspective_or_affine_deskew(bgr: np.ndarray, angle_deg: float) -> np.ndarray:
        """
        Attempt 4-point quadrilateral perspective rectification first; fall back to
        clean border-replicated affine rotation by `angle_deg`.
        """
        h, w = bgr.shape[:2]
        gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
        blur = cv2.GaussianBlur(gray, (3, 3), 0)
        _, thresh = cv2.threshold(blur, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        if contours:
            largest = max(contours, key=cv2.contourArea)
            if cv2.contourArea(largest) >= 0.42 * (w * h):
                peri = cv2.arcLength(largest, True)
                approx = cv2.approxPolyDP(largest, 0.03 * peri, True)
                if len(approx) == 4:
                    pts = approx.reshape(4, 2).astype(np.float32)
                    s = pts.sum(axis=1)
                    diff = np.diff(pts, axis=1).reshape(-1)
                    rect = np.zeros((4, 2), dtype=np.float32)
                    rect[0] = pts[np.argmin(s)]      # top-left
                    rect[2] = pts[np.argmax(s)]      # bottom-right
                    rect[1] = pts[np.argmin(diff)]   # top-right
                    rect[3] = pts[np.argmax(diff)]   # bottom-left

                    dst = np.array(
                        [[0, 0], [w - 1, 0], [w - 1, h - 1], [0, h - 1]],
                        dtype=np.float32,
                    )
                    mat = cv2.getPerspectiveTransform(rect, dst)
                    return cv2.warpPerspective(bgr, mat, (w, h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)

        center = (w / 2.0, h / 2.0)
        rot_mat = cv2.getRotationMatrix2D(center, angle_deg, 1.0)
        return cv2.warpAffine(bgr, rot_mat, (w, h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)

    @staticmethod
    def apply_gamma(bgr: np.ndarray, gamma: float) -> np.ndarray:
        inv_gamma = 1.0 / max(0.1, gamma)
        lut = np.array([((i / 255.0) ** inv_gamma) * 255.0 for i in range(256)]).clip(0, 255).astype(np.uint8)
        return cv2.LUT(bgr, lut)

    @staticmethod
    def apply_clahe_lab(bgr: np.ndarray, clip_limit: float = 1.8) -> np.ndarray:
        lab = cv2.cvtColor(bgr, cv2.COLOR_BGR2LAB)
        l_ch, a_ch, b_ch = cv2.split(lab)
        clahe = cv2.createCLAHE(clipLimit=clip_limit, tileGridSize=(4, 4))
        l_eq = clahe.apply(l_ch)
        return cv2.cvtColor(cv2.merge((l_eq, a_ch, b_ch)), cv2.COLOR_LAB2BGR)

    @staticmethod
    def resize_with_aspect_padding(
        bgr: np.ndarray,
        target_w: int = LPRNET_INPUT_WIDTH,
        target_h: int = LPRNET_INPUT_HEIGHT,
    ) -> np.ndarray:
        """
        Resize to target_h while preserving character aspect ratio (critical for distinguishing
        Z's sharp horizontal/diagonal strokes from 2's curved arch), padding width symmetrically
        when narrower or resizing smoothly when wider.
        """
        h, w = bgr.shape[:2]
        scale = target_h / max(1.0, float(h))
        new_w = max(8, int(round(w * scale)))

        if new_w <= target_w:
            interp = cv2.INTER_CUBIC if scale > 1.0 else cv2.INTER_AREA
            resized = cv2.resize(bgr, (new_w, target_h), interpolation=interp)
            pad_total = target_w - new_w
            pad_left = pad_total // 2
            pad_right = pad_total - pad_left
            return cv2.copyMakeBorder(resized, 0, 0, pad_left, pad_right, cv2.BORDER_REPLICATE)

        # Slightly wider than 94:24 aspect ratio -> area/cubic resize to (target_w, target_h)
        interp = cv2.INTER_AREA if w > target_w else cv2.INTER_CUBIC
        return cv2.resize(bgr, (target_w, target_h), interpolation=interp)

    def preprocess(
        self,
        bgr_crop: np.ndarray,
        quality: Optional[CropQualityMetrics] = None,
    ) -> Tuple[np.ndarray, np.ndarray, List[str], CropQualityMetrics]:
        """
        Run configured preprocessing on `bgr_crop`.

        Returns:
            (chw_float_tensor_np, processed_bgr_94x24, applied_ops, quality_metrics)
            where `chw_float_tensor_np` has shape (3, 24, 94) in RGB order normalized to [-1, 1].
        """
        metrics = quality if quality is not None else self.assess_quality(bgr_crop)
        applied_ops: List[str] = []

        if bgr_crop is None or bgr_crop.size == 0:
            blank = np.zeros((LPRNET_INPUT_HEIGHT, LPRNET_INPUT_WIDTH, 3), dtype=np.uint8)
            chw = np.zeros((3, LPRNET_INPUT_HEIGHT, LPRNET_INPUT_WIDTH), dtype=np.float32)
            return chw, blank, ["empty_fallback"], metrics

        work = bgr_crop.copy()
        if work.ndim == 2:
            work = cv2.cvtColor(work, cv2.COLOR_GRAY2BGR)

        if self.mode == "raw_resize":
            proc_bgr = cv2.resize(work, (LPRNET_INPUT_WIDTH, LPRNET_INPUT_HEIGHT), interpolation=cv2.INTER_LINEAR)
            applied_ops.append("raw_resize")

        elif self.mode == "aspect_pad_only":
            proc_bgr = self.resize_with_aspect_padding(work)
            applied_ops.append("aspect_pad_resize")

        elif self.mode == "all_forced":
            work = self.trim_holder_borders(work)
            if abs(metrics.skew_angle_deg) > 1.0:
                work = self.perspective_or_affine_deskew(work, metrics.skew_angle_deg)
            work = cv2.bilateralFilter(work, d=5, sigmaColor=35, sigmaSpace=35)
            work = self.apply_clahe_lab(work, clip_limit=2.0)
            blur = cv2.GaussianBlur(work, (0, 0), 1.2)
            work = cv2.addWeighted(work, 1.35, blur, -0.35, 0)
            proc_bgr = self.resize_with_aspect_padding(work)
            applied_ops.extend(["trim", "deskew", "bilateral_denoise", "clahe", "sharpen", "aspect_pad_resize"])

        else:
            # conditional_smart: apply ONLY operations warranted by measured crop conditions
            trimmed = self.trim_holder_borders(work)
            if trimmed.shape != work.shape:
                work = trimmed
                applied_ops.append("trim_holder_borders")

            if abs(metrics.skew_angle_deg) >= self.min_deskew_angle_deg:
                work = self.perspective_or_affine_deskew(work, metrics.skew_angle_deg)
                applied_ops.append(f"deskew({metrics.skew_angle_deg:+.1f}deg)")

            if metrics.noise_sigma >= self.high_noise_threshold:
                work = cv2.bilateralFilter(work, d=5, sigmaColor=30, sigmaSpace=30)
                applied_ops.append("bilateral_denoise")

            if metrics.mean_brightness < self.low_light_threshold:
                work = self.apply_gamma(work, gamma=1.45)
                applied_ops.append("gamma_brighten")
            elif metrics.mean_brightness > self.glare_threshold:
                work = self.apply_gamma(work, gamma=0.72)
                applied_ops.append("gamma_deglare")

            if metrics.contrast_std < self.low_contrast_threshold:
                work = self.apply_clahe_lab(work, clip_limit=1.8)
                applied_ops.append("clahe_contrast")

            # Mild unsharp mask ONLY on moderately soft, low-noise crops (never sharpen noisy or already crisp crops)
            if 28.0 <= metrics.blur_score < 95.0 and metrics.noise_sigma < self.high_noise_threshold:
                soft = cv2.GaussianBlur(work, (0, 0), 1.0)
                work = cv2.addWeighted(work, 1.28, soft, -0.28, 0)
                applied_ops.append("mild_sharpen")

            proc_bgr = self.resize_with_aspect_padding(work)
            applied_ops.append("aspect_pad_resize")

        # Convert BGR -> RGB and normalize identically for training and inference: [-1.0, +1.0]
        rgb = cv2.cvtColor(proc_bgr, cv2.COLOR_BGR2RGB).astype(np.float32)
        rgb = (rgb - 127.5) * 0.0078125
        chw = np.transpose(rgb, (2, 0, 1))  # (3, 24, 94)

        return chw, proc_bgr, applied_ops, metrics


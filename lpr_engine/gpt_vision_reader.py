"""
GPT Vision Reader (Fourth Reader for Philippine License Plate Recognition).
Uses OpenAI GPT-4o / GPT-4o-mini multimodal vision capabilities to extract license plate
characters with high precision, especially for resolving visual ambiguities:
- Z versus 2
- D versus 0
- B versus 8
- S versus 5
- I versus 1

Supports:
- Environment variable: OPENAI_API_KEY
- Local configuration: openai_config.json
- Graceful standby mode: If no API key is provided, logs a warning and allows the other
  three readers (EasyOCR, PaddleOCR, LPRNet) to continue voting without error.
"""

import base64
import json
import os
import re
import time
from typing import Any, Dict, Optional, Tuple

import cv2
import numpy as np

try:
    from openai import OpenAI, AuthenticationError, RateLimitError, APITimeoutError
    OPENAI_AVAILABLE = True
except ImportError:
    OpenAI = None
    OPENAI_AVAILABLE = False


DEFAULT_CONFIG_PATH = os.path.join(os.path.dirname(os.path.dirname(__file__)), "openai_config.json")

SYSTEM_PROMPT = """You are an expert OCR vision system dedicated to reading vehicle license plates in the Philippines.
Your mission is to read the exact alphanumeric characters visible on the cropped license plate image.

Philippine License Plate Format Reference:
1. Standard 4-Wheel Private/Commercial (Current): 3 letters followed by 4 digits (e.g. ABC 1234, NDI 2459).
2. Legacy 4-Wheel: 3 letters followed by 3 digits (e.g. ABC 123, WTI 926).
3. Conduction Sticker / Temporary: 6 characters: 1 letter + 3 digits + 2 letters, or letter+digits (e.g. D566CP, D404DB).
4. Motorcycle (New): 2 letters followed by 5 digits (e.g. ND 12345).
5. Motorcycle (Legacy): 2 letters + 4 digits (e.g. NC 1234).

Strict Character Disambiguation Rules:
- Distinguish 'Z' from '2' (horizontal top and bottom with diagonal stem vs curved shoulder).
- Distinguish 'D' from '0' (straight left vertical spine with smooth rounded curve vs symmetric oval).
- Distinguish 'B' from '8' (vertical left spine with two distinct lobes vs continuous figure-eight loops).
- Distinguish 'S' from '5' (smooth continuous S-curve vs flat sharp top horizontal bar with vertical neck).
- Distinguish 'I' from '1' (straight vertical stroke vs top serif hook).

Respond ONLY with valid JSON in this exact structure:
{
  "plate": "NDI2459",
  "confidence": 0.98,
  "plate_format": "standard_4w",
  "notes": "Clear characters, distinguished D from 0"
}
Output only the alphanumeric plate string with NO dashes or spaces in the 'plate' field."""


class GPTVisionReader:
    """
    Fourth Reader integrating GPT-4o-mini / GPT-4o Vision for Philippine LPR.
    """

    def __init__(
        self,
        api_key: Optional[str] = None,
        model: str = "gpt-4o-mini",
        timeout: float = 3.5,
        config_path: str = DEFAULT_CONFIG_PATH
    ):
        self.config_path = config_path
        self.model = model
        self.timeout = timeout
        self.api_key = api_key or os.environ.get("OPENAI_API_KEY", "")
        self.enabled = True
        self.client: Optional[Any] = None
        self.last_quota_error_time = 0.0
        self._last_config_mtime = 0.0

        # Load from config file if available
        self._load_config()

        # Initialize OpenAI client if API key is present
        self._init_client()

    def _check_config_mtime(self):
        """Auto-reload config if openai_config.json was updated on disk."""
        if not os.path.exists(self.config_path):
            return
        try:
            mtime = os.path.getmtime(self.config_path)
            if mtime != self._last_config_mtime:
                self._last_config_mtime = mtime
                prev_key = self.api_key
                prev_model = self.model
                self._load_config()
                if self.api_key != prev_key or self.model != prev_model or self.client is None:
                    print("[GPTVision] Detected update in openai_config.json, reinitializing client...")
                    self._init_client()
        except Exception:
            pass

    def _load_config(self):
        """Load configuration from JSON file if present."""
        if os.path.exists(self.config_path):
            try:
                self._last_config_mtime = os.path.getmtime(self.config_path)
                with open(self.config_path, "r", encoding="utf-8") as f:
                    cfg = json.load(f)
                    if cfg.get("api_key"):
                        self.api_key = cfg["api_key"].strip()
                    if cfg.get("model"):
                        self.model = cfg["model"].strip()
                    if "timeout" in cfg:
                        self.timeout = float(cfg["timeout"])
                    if "enabled" in cfg:
                        self.enabled = bool(cfg["enabled"])
            except Exception as e:
                print(f"[GPTVision] Warning reading config {self.config_path}: {e}")

    def _init_client(self):
        """Initialize the OpenAI client."""
        if not OPENAI_AVAILABLE:
            print("[GPTVision] Warning: 'openai' library is not installed. GPT Vision reader disabled.")
            self.client = None
            return

        if self.api_key and self.api_key != "YOUR_OPENAI_API_KEY_HERE":
            try:
                self.client = OpenAI(api_key=self.api_key, timeout=self.timeout)
                print(f"[GPTVision] Ready! Model: {self.model} (Timeout: {self.timeout}s)")
            except Exception as e:
                print(f"[GPTVision] Error initializing client: {e}")
                self.client = None
        else:
            print(f"[GPTVision] Standby mode: No OpenAI API key configured. "
                  f"(Add key in openai_config.json or OPENAI_API_KEY env var to activate).")
            self.client = None

    def is_configured(self) -> bool:
        """Check if GPT Vision has a valid client ready for inference."""
        self._check_config_mtime()
        if not (self.enabled and self.client is not None):
            return False
        # If OpenAI quota/credits were recently exhausted, temporarily back off to eliminate network lag
        if (time.time() - self.last_quota_error_time) < 300.0:
            return False
        return True

    def set_api_key(self, key: str, save_to_config: bool = True):
        """Update API key at runtime and optionally persist to openai_config.json."""
        self.api_key = key.strip()
        self._init_client()

        if save_to_config:
            cfg = {
                "api_key": self.api_key,
                "model": self.model,
                "enabled": self.enabled,
                "timeout": self.timeout
            }
            try:
                with open(self.config_path, "w", encoding="utf-8") as f:
                    json.dump(cfg, f, indent=2)
                print(f"[GPTVision] Saved API key to {self.config_path}")
            except Exception as e:
                print(f"[GPTVision] Error saving config: {e}")

    def recognize(self, bgr_crop: np.ndarray) -> Tuple[str, float]:
        """
        Send cropped license plate image to GPT Vision.
        Returns:
            Tuple[str, float]: (clean_plate_text, confidence_score)
        """
        if not self.is_configured():
            return "", 0.0

        if bgr_crop is None or bgr_crop.size == 0:
            return "", 0.0

        try:
            h, w = bgr_crop.shape[:2]
            # Ensure crop has sufficient height for visual clarity (min 80px)
            if h < 80:
                scale = 80.0 / float(max(1, h))
                target_w = max(10, int(w * scale))
                crop_to_send = cv2.resize(bgr_crop, (target_w, 80), interpolation=cv2.INTER_CUBIC)
            else:
                crop_to_send = bgr_crop

            # Encode image to JPEG in memory
            success, buffer = cv2.imencode(".jpg", crop_to_send, [cv2.IMWRITE_JPEG_QUALITY, 92])
            if not success:
                return "", 0.0

            b64_image = base64.b64encode(buffer).decode("utf-8")
            data_url = f"data:image/jpeg;base64,{b64_image}"

            # Call OpenAI Chat Completion with vision
            response = self.client.chat.completions.create(
                model=self.model,
                messages=[
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {
                        "role": "user",
                        "content": [
                            {
                                "type": "text",
                                "text": "Read the exact Philippine license plate characters from this cropped image."
                            },
                            {
                                "type": "image_url",
                                "image_url": {
                                    "url": data_url,
                                    "detail": "high"
                                }
                            }
                        ]
                    }
                ],
                response_format={"type": "json_object"},
                temperature=0.0,
                max_tokens=150
            )

            raw_content = response.choices[0].message.content or ""
            data = json.loads(raw_content)

            plate = str(data.get("plate", "")).strip().upper()
            clean_plate = re.sub(r'[^A-Z0-9]', '', plate)
            conf = float(data.get("confidence", 0.95))
            conf = max(0.1, min(1.0, conf))

            return clean_plate, conf

        except Exception as e:
            # Handle rate limits, timeouts, or network interruptions gracefully
            err_str = str(e)
            if "quota" in err_str.lower() or "billing" in err_str.lower() or "429" in err_str or "credit_balance" in err_str:
                self.last_quota_error_time = time.time()
                print(f"[GPTVision] Quota/credits exhausted: Pausing API calls for 5m to avoid detection delays. ({err_str[:60]}...)")
            elif "timeout" in err_str.lower():
                print(f"[GPTVision] Timeout after {self.timeout}s")
            else:
                print(f"[GPTVision] Inference note: {err_str[:80]}")
            return "", 0.0


# =========================================================
#  DUAL CAMERA LICENSE PLATE DETECTION SERVER
#  (YOLOv10 + EasyOCR + Tapo C500 / USB Webcams + MySQL)
# =========================================================
#
#  This server:
#   1. Connects to two independent camera channels:
#        - Entrance Cam (Direction: IN, IP 192.168.1.18)
#        - Exit Cam (Direction: OUT, IP 192.168.1.19) - EXIT ONLY
#      Supports Tapo C500 RTSP streams and USB webcams.
#   2. Runs YOLOv10 plate detection on both camera frames.
#   3. Uses EasyOCR with CLAHE contrast enhancement & rotation checks.
#   4. Logs detected plates into MySQL (vehicle_logs, vehicle_log_dates).
#   5. Serves low-latency MJPEG video streams:
#        - /video/entrance (alias /video)
#        - /video/exit
#   6. Provides live camera management API at /api/cameras.
#
# =========================================================

import os
# Keep video decoding and parallel OCR from each claiming all CPU cores.
for _thread_limit in ('OMP_NUM_THREADS', 'MKL_NUM_THREADS', 'OPENBLAS_NUM_THREADS'):
    os.environ.setdefault(_thread_limit, '2')
# PyTorch (YOLO/EasyOCR) and Paddle share one 4 GB GPU: make Paddle allocate VRAM
# on demand instead of reserving a big block up front.
os.environ.setdefault('FLAGS_allocator_strategy', 'auto_growth')
os.environ.setdefault('FLAGS_fraction_of_gpu_memory_to_use', '0.25')
os.environ.setdefault("OPENCV_LOG_LEVEL", "OFF")
import cv2
cv2.setNumThreads(2)
from app.vehicle_visit_logger import record_vehicle_visit
from app.exit_alerts import record_exit_without_entry, attach_alert_image
from app.pending_visitor_queue import (
    enqueue_pending_visitor,
    has_open_visitor_visit,
    is_visitor_pending_or_suppressed,
    clear_pending_visitor_for_plate,
)
from lpr_engine.plate_tracker import PlateTracker
from lpr_engine.capture_store import save_plate_capture
from app.vehicle_capture import VehicleCapturer
import time
import threading
import os
import re
import sys
import json
import subprocess
import importlib
import numpy as np
from datetime import datetime, timedelta
from flask import Flask, Response, jsonify, request
try:
    from flask_cors import CORS
except ImportError:
    CORS = None

# Hardware Acceleration & PyTorch Threading Setup
import torch

USE_CUDA = torch.cuda.is_available()
DEVICE = "cuda:0" if USE_CUDA else "cpu"

if USE_CUDA:
    # Enable cuDNN autotuner and benchmark mode for high-throughput GPU inference
    torch.backends.cudnn.benchmark = True
    torch.backends.cudnn.enabled = True
    try:
        _dev_name = torch.cuda.get_device_name(0)
        _cuda_ver = torch.version.cuda
        print(f"[INFO] *** GPU ACCELERATION ACTIVE *** -> Device: {_dev_name} (CUDA {_cuda_ver})")
    except Exception:
        print("[INFO] *** GPU ACCELERATION ACTIVE (CUDA) ***")
else:
    # Optimize PyTorch CPU thread allocation if running on CPU
    torch.set_num_threads(2)
    print("[INFO] CUDA GPU not detected by PyTorch. Running on CPU multi-threading.")

# Ensure bundled yolov10 package takes priority over site-packages ultralytics
_yolov10_pkg_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "yolov10")
if os.path.isdir(_yolov10_pkg_dir) and _yolov10_pkg_dir not in sys.path:
    sys.path.insert(0, _yolov10_pkg_dir)

# Provide lightweight dill._dill._load_type fallback if checkpoint requires dill offline
try:
    import dill  # noqa: F401
except ImportError:
    import builtins as _builtins
    import types as _types
    _dill_mod = _types.ModuleType("dill")
    _dill_sub = _types.ModuleType("dill._dill")
    _TYPE_MAP = {
        "ClassType": type,
        "ObjectType": object,
        "NoneType": type(None),
    }
    def _load_type(name):
        if name in _TYPE_MAP:
            return _TYPE_MAP[name]
        if hasattr(_builtins, name):
            return getattr(_builtins, name)
        return getattr(_types, name)
    _dill_sub._load_type = _load_type
    _dill_mod._dill = _dill_sub
    sys.modules.setdefault("dill", _dill_mod)
    sys.modules.setdefault("dill._dill", _dill_sub)

# Allowlist YOLOv10 weights loading in PyTorch 2.6+
import torch.serialization
try:
    from ultralytics.nn.tasks import YOLOv10DetectionModel
    torch.serialization.add_safe_globals([YOLOv10DetectionModel])
except Exception:
    pass

def _safe_torch_load(*args, **kwargs):
    if "weights_only" not in kwargs:
        kwargs["weights_only"] = False
    return torch.serialization.load(*args, **kwargs)

torch.load = _safe_torch_load

# Force TCP transport + low-latency nobuffer flags for RTSP streaming on Tapo C500
os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = (
    "rtsp_transport;tcp|fflags;nobuffer|flags;low_delay|max_delay;0|reorder_queue_size;0|stimeout;5000000"
)

# Redirect Paddle and model caches inside workspace to avoid sandbox permission barriers
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
paddle_cache = os.path.join(BASE_DIR, ".paddle_cache")
os.environ["PADDLE_HOME"] = paddle_cache
os.environ["PADDLEX_HOME"] = paddle_cache
os.environ["USERPROFILE"] = paddle_cache
os.environ["HOME"] = paddle_cache

# OpenVINO GPU acceleration (Targets NVIDIA GeForce GTX dGPU / GPU via OpenCL with kernel cache)
os.environ["OPENVINO_DEVICE"] = "GPU"
OPENVINO_CACHE_DIR = os.path.join(BASE_DIR, ".openvino_cache")
os.makedirs(OPENVINO_CACHE_DIR, exist_ok=True)

OV_GPU_AVAILABLE = False
OV_GPU_NAME = ""
if not USE_CUDA:
    try:
        import openvino as _ov
        _OrigOVCore = _ov.Core

        def _CachedOVCore(*args, **kwargs):
            c = _OrigOVCore(*args, **kwargs)
            try:
                c.set_property({"CACHE_DIR": OPENVINO_CACHE_DIR})
            except Exception:
                pass
            return c

        _ov.Core = _CachedOVCore
        _probe_core = _ov.Core()
        if "GPU" in _probe_core.available_devices:
            OV_GPU_AVAILABLE = True
            OV_GPU_NAME = str(_probe_core.get_property("GPU", "FULL_DEVICE_NAME")).strip() or "OpenVINO GPU"
            print(f"[INFO] *** GPU ACCELERATION ACTIVE (OpenVINO) *** -> Device: {OV_GPU_NAME}")
    except Exception:
        OV_GPU_AVAILABLE = False

# =========================================================
#  CONFIGURATION & PATHS
# =========================================================

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# YOLOv10 model weights path (prefer native CUDA GPU when available, else OpenVINO GPU, else CPU .pt)
PYTORCH_MODEL_PATH = os.path.join(BASE_DIR, "weights", "best.pt")
OPENVINO_MODEL_PATH = os.path.join(BASE_DIR, "weights", "best_openvino_model")
USE_INTEL_GPU = (not USE_CUDA) and OV_GPU_AVAILABLE and os.path.exists(OPENVINO_MODEL_PATH)
MODEL_PATH = PYTORCH_MODEL_PATH if (USE_CUDA and os.path.exists(PYTORCH_MODEL_PATH)) else (
    OPENVINO_MODEL_PATH if os.path.exists(OPENVINO_MODEL_PATH) else PYTORCH_MODEL_PATH
)
FALLBACK_MODEL_PATH = os.path.join(BASE_DIR, "yolov10", "weights", "yolov10s.pt")
# General YOLOv10 (COCO) model used to photograph the vehicle after its plate is read
VEHICLE_MODEL_PATH = os.path.join(BASE_DIR, "weights", "yolov10n_vehicle.pt")
ANY_GPU_ACTIVE = USE_CUDA or USE_INTEL_GPU or OV_GPU_AVAILABLE

# Camera configuration JSON file
CONFIG_FILE = os.path.join(BASE_DIR, "camera_config.json")

# Default camera sources (Local USB webcams 0 and 1, or RTSP URLs)
DEFAULT_ENTRANCE_RTSP_URL = os.environ.get("ENTRANCE_SOURCE", "0")
DEFAULT_EXIT_RTSP_URL = os.environ.get("EXIT_SOURCE", "1")
DEFAULT_RTSP_URL = DEFAULT_ENTRANCE_RTSP_URL

# Detection settings (Dynamically tuned for GPU / Intel UHD with CPU fallback)
DETECTION_CANDIDATE_THRESHOLD = 0.22        # Let distant plate boxes reach OCR
CONFIDENCE_THRESHOLD = 0.60                  # Require at least 60% final reading confidence
IMAGE_SIZE = 640 if ANY_GPU_ACTIVE else 480  # 640px on GPU for max plate accuracy; 480px on CPU for low latency
JPEG_QUALITY = 75 if ANY_GPU_ACTIVE else 65  # Higher visual MJPEG streaming quality on GPU
OVERLAY_TTL_SECONDS = 1.6                    # Keep bounding box overlay visible smoothly between AI passes
INFERENCE_INTERVAL_SECONDS = 0.03 if ANY_GPU_ACTIVE else 0.05 # 30ms (~33 FPS AI pass) on GPU, 50ms on CPU
PLATE_COOLDOWN_SECONDS = 30                  # 30-second cooldown prevents spam while car is at the barrier
EXIT_ALERT_REPEAT_SECONDS = 600              # one exit-without-entry warning per plate per 10 minutes

# Camera orientation settings
FLIP_CAMERA_180 = False
AUTO_CHECK_UPSIDE_DOWN = False

# Optional GPU (D3D11VA) H.264 decoding. OFF by default: with the pip opencv-python
# build on this laptop FFmpeg's D3D11 init fails and silently falls back to CPU
# (1080p CPU decode is only ~7 ms/frame anyway). Set SECSYS_HW_DECODE=1 to try it.
USE_HW_VIDEO_DECODE = os.environ.get("SECSYS_HW_DECODE", "0") == "1"

# OCR settings
OCR_LANGUAGES = ['en']

# Database settings (matches PHP config)
DB_CONFIG = {
    'host': os.environ.get('SECSYS_DB_HOST', '127.0.0.1'),
    'port': int(os.environ.get('SECSYS_DB_PORT', '3306')),
    'user': os.environ.get('SECSYS_DB_USER', 'root'),
    'password': os.environ.get('SECSYS_DB_PASS', ''),
    'database': os.environ.get('SECSYS_DB_NAME', 'security_system')
}

# Flask server
SERVER_HOST = "0.0.0.0"
SERVER_PORT = 5000


# =========================================================
#  CAMERA CONFIGURATION PERSISTENCE
# =========================================================

DEFAULT_CAMERA_CONFIG = {
    "entrance": {
        "name": "Entrance Cam",
        "source": DEFAULT_ENTRANCE_RTSP_URL,
        "direction": "IN",
        "flip_180": False,
        "zoom_factor": 1.35,
        "enabled": True
    },
    "exit": {
        "name": "Exit Cam",
        "source": DEFAULT_EXIT_RTSP_URL,
        "direction": "OUT",
        "flip_180": False,
        "zoom_factor": 1.35,
        "enabled": DEFAULT_ENTRANCE_RTSP_URL.strip() != DEFAULT_EXIT_RTSP_URL.strip()
    },
    "camera_mode": "single" if DEFAULT_ENTRANCE_RTSP_URL.strip() == DEFAULT_EXIT_RTSP_URL.strip() else "dual",
    "single_direction": "AUTO"
}


def _sanitize_loaded_camera_config(cfg):
    """Ensure persisted startup config does not crash when both channels point to the same camera."""
    for key in ("entrance", "exit"):
        if key not in cfg or not isinstance(cfg[key], dict):
            cfg[key] = DEFAULT_CAMERA_CONFIG[key].copy()
        else:
            for field, default_val in DEFAULT_CAMERA_CONFIG[key].items():
                cfg[key].setdefault(field, default_val)

    cfg.setdefault("camera_mode", DEFAULT_CAMERA_CONFIG["camera_mode"])
    cfg.setdefault("single_direction", DEFAULT_CAMERA_CONFIG["single_direction"])
    if cfg["single_direction"] not in ("AUTO", "IN", "OUT"):
        cfg["single_direction"] = "AUTO"

    ent_src = str(cfg["entrance"].get("source", "")).strip()
    exit_src = str(cfg["exit"].get("source", "")).strip()
    if (
        cfg.get("camera_mode") == "dual"
        and cfg["entrance"].get("enabled", True)
        and cfg["exit"].get("enabled", True)
        and ent_src
        and ent_src == exit_src
    ):
        print(
            f"[WARNING] Entrance and Exit share the same camera source ({ent_src}). "
            f"Switching startup camera_mode to 'single' ({cfg['single_direction']})."
        )
        cfg["camera_mode"] = "single"
        save_camera_config(cfg)

    return cfg


def load_camera_config():
    """Load camera source settings from JSON file or create defaults."""
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cfg = json.load(f)
                return _sanitize_loaded_camera_config(cfg)
        except Exception as e:
            print(f"[WARNING] Failed to read {CONFIG_FILE}: {e}")

    default_cfg = json.loads(json.dumps(DEFAULT_CAMERA_CONFIG))
    save_camera_config(default_cfg)
    return default_cfg


def save_camera_config(cfg):
    """Save camera settings to JSON file."""
    try:
        with open(CONFIG_FILE, "w", encoding="utf-8") as f:
            json.dump(cfg, f, indent=4)
        return True
    except Exception as e:
        print(f"[ERROR] Failed to save {CONFIG_FILE}: {e}")
        return False


# =========================================================
#  LOAD YOLOv10 MODEL
# =========================================================

def load_model():
    """Load the YOLOv10 model (OpenVINO for Intel UHD iGPU, or PyTorch .pt fallback)."""
    yolov10_dir = os.path.join(BASE_DIR, "yolov10")
    if os.path.isdir(yolov10_dir):
        if yolov10_dir in sys.path:
            sys.path.remove(yolov10_dir)
        sys.path.insert(0, yolov10_dir)

        loaded_ultra = sys.modules.get("ultralytics")
        if loaded_ultra is not None and not hasattr(loaded_ultra, "YOLOv10"):
            for mod_name in [k for k in list(sys.modules.keys()) if k == "ultralytics" or k.startswith("ultralytics.")]:
                sys.modules.pop(mod_name, None)

    from ultralytics import YOLOv10

    # 1. Prefer OpenVINO model on GPU (e.g. NVIDIA GeForce GTX 1650 Ti) when native CUDA PyTorch is not active
    if USE_INTEL_GPU and os.path.exists(OPENVINO_MODEL_PATH):
        gpu_label = OV_GPU_NAME or "OpenVINO GPU"
        print(f"[INFO] *** GPU ACCELERATION ACTIVE (OpenVINO) -> {gpu_label} ***")
        print(f"[INFO] Loading YOLOv10 OpenVINO model from: {OPENVINO_MODEL_PATH}")
        model = YOLOv10(OPENVINO_MODEL_PATH, task="detect")
        print(f"[INFO] YOLOv10 OpenVINO model loaded successfully on {gpu_label}!")
        return model

    # 2. PyTorch .pt model fallback
    model_path = MODEL_PATH
    if not os.path.exists(model_path):
        print(f"[WARNING] Custom model not found at {model_path}")
        if os.path.exists(FALLBACK_MODEL_PATH):
            print(f"[INFO] Using fallback model: {FALLBACK_MODEL_PATH}")
            model_path = FALLBACK_MODEL_PATH
        else:
            print("[ERROR] No model weights found in weights/!")
            sys.exit(1)

    print(f"[INFO] Loading YOLOv10 model from: {model_path} (Target Device: {DEVICE})")
    model = YOLOv10(model_path)
    if USE_CUDA:
        try:
            model.to(DEVICE)
            print(f"[INFO] YOLOv10 model successfully moved to GPU: {torch.cuda.get_device_name(0)}")
            # Warm-up: let cuDNN pick kernels now so the first live frame doesn't stall the feed.
            _t0 = time.time()
            _dummy = np.zeros((IMAGE_SIZE, IMAGE_SIZE, 3), dtype=np.uint8)
            for _ in range(2):
                model.predict(source=_dummy, imgsz=IMAGE_SIZE, device=DEVICE, half=True, verbose=False)
            print(f"[INFO] YOLOv10 GPU warm-up done (FP16) in {time.time() - _t0:.1f}s")
        except Exception as e:
            print(f"[WARNING] Could not transfer YOLOv10 to GPU: {e}")
    print("[INFO] YOLOv10 model loaded successfully!")
    return model


# =========================================================
#  LOAD OCR ENGINE (TRIPLE-READER ENSEMBLE: EasyOCR + PaddleOCR + LPRNet)
# =========================================================

def load_ocr():
    """
    Load Triple-Reader OCR Ensemble:
    1. EasyOCR (Spatial token reader + contrast enhancement)
    2. PaddleOCR (PP-OCRv6 high-precision text recognition)
    3. LPRNet (Specialized deep CNN license plate character reader)
    """
    active_gpu_desc = torch.cuda.get_device_name(0) if USE_CUDA else (OV_GPU_NAME if OV_GPU_AVAILABLE else DEVICE)
    print(f"[INFO] Initializing OCR Ensemble (GPU Accelerated: {ANY_GPU_ACTIVE}, Target Device: {active_gpu_desc})...")

    # 1. EasyOCR (FP32 unquantized when GPU/OpenVINO GTX acceleration is active)
    easy_reader = None
    try:
        import easyocr
        easy_weights_dir = os.path.join(BASE_DIR, "weights", "easyocr")
        easy_user_net_dir = os.path.join(easy_weights_dir, "user_network")
        os.makedirs(easy_user_net_dir, exist_ok=True)
        print(f"[INFO] Loading EasyOCR (GPU: {ANY_GPU_ACTIVE}, storage: {easy_weights_dir})...")
        easy_reader = easyocr.Reader(
            OCR_LANGUAGES,
            gpu=USE_CUDA,
            quantize=not ANY_GPU_ACTIVE,
            model_storage_directory=easy_weights_dir,
            user_network_directory=easy_user_net_dir,
        )
        if OV_GPU_AVAILABLE and not USE_CUDA:
            from lpr_engine.gpu_char_extractor import GPUCharacterExtractor
            if GPUCharacterExtractor().accelerate_easyocr_reader(easy_reader):
                print(f"[INFO] EasyOCR CRAFT + VGG compiled onto {OV_GPU_NAME} (FP32 OpenVINO GPU)!")
        print(f"[INFO] EasyOCR loaded successfully (Accelerated: {ANY_GPU_ACTIVE})!")
    except Exception as e:
        print(f"[WARNING] EasyOCR failed to load: {e}")

    # 2. Recognition-only Paddle model: YOLO has already located the plate.
    paddle_reader = None
    try:
        model_dir = os.path.join(BASE_DIR, '.paddle_cache', '.paddlex', 'official_models', 'PP-OCRv6_medium_rec')
        # Only ask Paddle for the GPU when the installed paddlepaddle build has CUDA;
        # the CPU wheel raises on device='gpu:0' and PaddleOCR would drop out entirely.
        paddle_gpu = False
        if USE_CUDA:
            try:
                import paddle
                paddle_gpu = bool(paddle.device.is_compiled_with_cuda())
            except Exception:
                paddle_gpu = False
            if not paddle_gpu:
                print('[INFO] paddlepaddle is a CPU build -> PaddleOCR runs on CPU (YOLO/EasyOCR/LPRNet use the NVIDIA GPU).')
        try:
            from paddleocr import TextRecognition
            paddle_reader = TextRecognition(
                model_name='PP-OCRv6_medium_rec', model_dir=model_dir if os.path.isdir(model_dir) else None,
                device='gpu:0' if paddle_gpu else 'cpu', cpu_threads=2, enable_mkldnn=False)
            paddle_reader.plate_recognition_only = True
        except ImportError:
            from lpr_engine.paddle_rec_runner import LocalPPOCRv6Recognizer
            paddle_reader = LocalPPOCRv6Recognizer(
                model_dir=model_dir, use_gpu=paddle_gpu, cpu_threads=2)
        print('[INFO] PaddleOCR plate recognition model loaded (no redundant text detector).')
    except Exception as e:
        print(f'[WARNING] PaddleOCR failed to load: {e}')

    # 3. LPRNet
    lpr_recognizer = None
    try:
        from lpr_engine.recognizer import LPRNetRecognizer
        lprnet_weights = os.path.join(BASE_DIR, "weights", "lprnet_best.pth")
        if not os.path.exists(lprnet_weights):
            raise FileNotFoundError('No trained LPRNet checkpoint found; excluding the untrained reader')
        lpr_device = torch.device(DEVICE)
        print(f"[INFO] Loading LPRNet Recognizer (Device: {DEVICE})...")
        lpr_recognizer = LPRNetRecognizer(checkpoint_path=lprnet_weights, device=lpr_device)
        print(f"[INFO] LPRNet Recognizer loaded successfully on {DEVICE}!")
    except Exception as e:
        print(f"[WARNING] LPRNet failed to load: {e}")

    # 4. Load GPT Vision Reader (Fourth Reader)
    gpt_reader = None
    try:
        from lpr_engine.gpt_vision_reader import GPTVisionReader
        print("[INFO] Loading GPT Vision Reader (Fourth Reader)...")
        gpt_reader = GPTVisionReader()
        if gpt_reader.is_configured():
            print(f"[INFO] GPT Vision Reader loaded successfully (Model: {gpt_reader.model})!")
        else:
            print("[INFO] GPT Vision Reader initialized in standby (Key pending in openai_config.json).")
    except Exception as e:
        print(f"[WARNING] GPT Vision failed to initialize: {e}")

    # Construct Quad-Reader Ensemble (EasyOCR + PaddleOCR + LPRNet + GPT Vision)
    try:
        from lpr_engine.quad_ensemble import QuadReaderEnsemble
        ensemble = QuadReaderEnsemble(
            easyocr_reader=easy_reader,
            paddleocr_reader=paddle_reader,
            lprnet_recognizer=lpr_recognizer,
            gpt_vision_reader=gpt_reader,
            min_accept_confidence=0.65,
            min_sharpness=20.0
        )
        print("[INFO] QuadReaderEnsemble ready with active readers: "
              f"EasyOCR={'YES' if easy_reader else 'NO'}, "
              f"PaddleOCR={'YES' if paddle_reader else 'NO'}, "
              f"LPRNet={'YES' if lpr_recognizer else 'NO'}, "
              f"GPTVision={'ACTIVE' if (gpt_reader and gpt_reader.is_configured()) else 'STANDBY'}")
        return ensemble
    except Exception as e:
        print(f"[ERROR] Failed to initialize QuadReaderEnsemble: {e}")
        return None


# =========================================================
#  DATABASE CONNECTION & LOGGING
# =========================================================

def get_db_connection():
    """Create a MySQL database connection (tries 3306 then 3307 automatically)."""
    try:
        import mysql.connector
    except Exception as e:
        print(f"[WARNING] Database connection failed: {e}")
        return None

    env_port = os.environ.get('SECSYS_DB_PORT')
    ports = [int(env_port)] if env_port else [DB_CONFIG['port']] + [p for p in (3306, 3307) if p != DB_CONFIG['port']]
    last_err = None
    for port in ports:
        try:
            cfg = dict(DB_CONFIG, port=port, connection_timeout=3)
            conn = mysql.connector.connect(**cfg)
            DB_CONFIG['port'] = port
            return conn
        except Exception as e:
            last_err = e
    print(f"[WARNING] Database connection failed: {last_err}")
    return None


def check_plate_in_database(plate_number):
    """
    Check if a plate number is registered in the system.
    Returns dict with category, vehicle_type, registered_vehicle_id, visitor_vehicle_id, purpose, official_plate.
    Strict rule: Only plates found in registered_vehicles WHERE status = 'Active' get category = 'Registered'.
    All other plates get category = 'Visitor'.
    """
    norm = normalize_plate(plate_number)
    info = {
        'category': 'Visitor',
        'vehicle_type': 'Car',
        'registered_vehicle_id': None,
        'visitor_vehicle_id': None,
        'purpose': None,
        'official_plate': plate_number
    }

    conn = get_db_connection()
    if not conn:
        return info

    try:
        cursor = conn.cursor(dictionary=True)

        # 1. Exact match in registered_vehicles WHERE status = 'Active'
        cursor.execute(
            "SELECT id, plate_number, vehicle_type FROM registered_vehicles "
            "WHERE UPPER(REGEXP_REPLACE(plate_number, '[^a-zA-Z0-9]', '')) = %s "
            "AND status = 'Active' LIMIT 1",
            (norm,)
        )
        result = cursor.fetchone()
        if result:
            info['category'] = 'Registered'
            info['vehicle_type'] = result.get('vehicle_type') or 'Car'
            info['registered_vehicle_id'] = result.get('id')
            info['official_plate'] = result.get('plate_number')
            return info

        # 2. If NOT found in registered_vehicles, it is STRICTLY a Visitor!
        # Check visitor_vehicles only to retain previous metadata (vehicle_type, purpose, visitor_vehicle_id)
        cursor.execute(
            "SELECT id, plate_number, vehicle_type, purpose FROM visitor_vehicles "
            "WHERE UPPER(REGEXP_REPLACE(plate_number, '[^a-zA-Z0-9]', '')) = %s "
            "ORDER BY id DESC LIMIT 1",
            (norm,)
        )
        res_vis = cursor.fetchone()
        if res_vis:
            info['category'] = 'Visitor'
            info['vehicle_type'] = res_vis.get('vehicle_type') or 'Car'
            info['visitor_vehicle_id'] = res_vis.get('id')
            info['purpose'] = res_vis.get('purpose')
            info['official_plate'] = res_vis.get('plate_number')
            return info

        # 3. New visitor vehicle
        info['category'] = 'Visitor'
        return info
    except Exception as e:
        print(f"[ERROR] Database query failed: {e}")
        return info
    finally:
        if conn:
            conn.close()


def log_vehicle_detection(plate_number, db_info, direction='IN'):
    """Commit a fixed-direction event, or infer shared-camera direction from its open visit."""
    conn = get_db_connection()
    if not conn:
        return False
    try:
        result = record_vehicle_visit(conn, plate_number, db_info, direction)
        if not result:
            return None
        invalidate_plate_state_cache(plate_number)
        print(f"[DB] Logged detection ({result['direction']}): {plate_number} (id={result['log_id']})")
        return result
    except Exception as e:
        print(f'[ERROR] Failed to log detection: {e}')
        return False
    finally:
        conn.close()


_PLATE_INSIDE_CACHE = {}
_PLATE_INSIDE_CACHE_TTL = 1.0


def invalidate_plate_state_cache(plate_number=None):
    """Invalidate cached inside/outside state for a plate (or all plates)."""
    if not plate_number:
        _PLATE_INSIDE_CACHE.clear()
        return
    norm = re.sub(r'[^A-Z0-9]', '', str(plate_number).upper())
    _PLATE_INSIDE_CACHE.pop(norm, None)
    for k in list(_PLATE_INSIDE_CACHE.keys()):
        if plates_are_similar(norm, k):
            _PLATE_INSIDE_CACHE.pop(k, None)


def is_plate_currently_inside(plate_number, use_cache=True):
    """
    Return True if the vehicle currently has an open 'IN' visit in the database,
    False if it is currently Outside, or None if database check is unavailable.
    """
    norm = re.sub(r'[^A-Z0-9]', '', str(plate_number or '').upper())
    if not norm:
        return False
    now_ts = time.monotonic()
    if use_cache:
        cached = _PLATE_INSIDE_CACHE.get(norm)
        if cached is not None and (now_ts - cached[1]) <= _PLATE_INSIDE_CACHE_TTL:
            return cached[0]
    conn = get_db_connection()
    if not conn:
        return None
    try:
        inside = bool(has_open_visitor_visit(conn, norm))
        _PLATE_INSIDE_CACHE[norm] = (inside, now_ts)
        return inside
    except Exception:
        return None
    finally:
        try:
            conn.close()
        except Exception:
            pass


def should_scan_plate_on_direction(plate_number, direction, use_cache=True):
    """
    Enforce directional scan rules:
      - Entrance ('IN'): Do NOT scan if vehicle is already Inside (or already pending visitor confirmation).
      - Exit ('OUT'): Do NOT scan if vehicle is currently Outside (no active open 'IN' visit).
      - Shared ('AUTO'): Always allow scan so direction is inferred from open visit state.
    """
    if direction not in ('IN', 'OUT'):
        return True
    norm = re.sub(r'[^A-Z0-9]', '', str(plate_number or '').upper())
    if not norm:
        return False
    if direction == 'IN':
        if is_visitor_pending_or_suppressed(norm, cooldown_seconds=PLATE_COOLDOWN_SECONDS):
            return False
        inside = is_plate_currently_inside(norm, use_cache=use_cache)
        if inside is True:
            return False
        return True
    if direction == 'OUT':
        inside = is_plate_currently_inside(norm, use_cache=use_cache)
        if inside is False:
            return False
        return True
    return True


# =========================================================
#  PLATE TEXT PROCESSING & EXACT PHILIPPINE SYNTAX ENGINE
# =========================================================

NON_PLATE_WORDS = {
    'REGISTERED', 'PHILIPPINES', 'NCR', 'IND', 'MATATAG', 'REPUBLIKA',
    'REGION', 'DEALER', 'LTO', 'PILIPINAS', 'METRO', 'MANILA', 'CALABARZON',
    'CENTRAL', 'LUZON', 'VISAYAS', 'MINDANAO', 'FOR', 'REGISTRATION'
}

# Positional substitution maps for Philippine plates
# When character is in a LETTER position:
DIGIT_TO_LETTER = {
    '0': 'D',  # D vs 0 (e.g. 0566CP -> D566CP)
    '1': 'I',
    '2': 'Z',  # Z vs 2 (e.g. N838R2 -> N838RZ)
    '4': 'A',
    '5': 'S',  # S vs 5
    '6': 'G',
    '7': 'T',
    '8': 'B',  # B vs 8
}

# When character is in a DIGIT position:
LETTER_TO_DIGIT = {
    'O': '0',
    'D': '0',  # D vs 0
    'Q': '0',
    'I': '1',
    'L': '1',
    'T': '1',
    'Z': '2',  # Z vs 2
    'A': '4',
    'S': '5',  # S vs 5
    'G': '6',
    'B': '8',  # B vs 8
    'R': '8',
}

# Standard Philippine Plate Formats:
# (name, exact_length, letter_positions, digit_positions, display_formatter)
PATTERNS = [
    ('conduction', 6, [0, 4, 5], [1, 2, 3], lambda s: f"{s[0]} {s[1:4]} {s[4:6]}"),
    ('standard_4w', 7, [0, 1, 2], [3, 4, 5, 6], lambda s: f"{s[0:3]} {s[3:7]}"),
    ('legacy_4w', 6, [0, 1, 2], [3, 4, 5], lambda s: f"{s[0:3]} {s[3:6]}"),
    ('motorcycle_7', 7, [0, 1], [2, 3, 4, 5, 6], lambda s: f"{s[0:2]} {s[2:7]}"),
    ('motorcycle_6', 6, [0, 1], [2, 3, 4, 5], lambda s: f"{s[0:2]} {s[2:6]}"),
    ('motorcycle_alt', 6, [3, 4, 5], [0, 1, 2], lambda s: f"{s[0:3]} {s[3:6]}"),
    ('numeric_suffix_6', 6, [5], [0, 1, 2, 3, 4], lambda s: s),
    ('numeric_prefix_6', 6, [4, 5], [0, 1, 2, 3], lambda s: f"{s[:4]} {s[4:]}"),
    ('numeric_identifier_11', 11, [], list(range(11)), lambda s: f"{s[:4]}-{s[4:]}"),
]

_REG_CACHE = {'timestamp': 0.0, 'data': {}}


def get_cached_registered_plates():
    """Cache active registered plates from MySQL for fast prioritization and syntax matching."""
    global _REG_CACHE
    now = time.time()
    if now - _REG_CACHE['timestamp'] < 5.0 and _REG_CACHE['data']:
        return _REG_CACHE['data']

    conn = get_db_connection()
    if not conn:
        return _REG_CACHE['data']

    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT id, plate_number, vehicle_type FROM registered_vehicles WHERE status = 'Active'"
        )
        rows = cursor.fetchall()
        reg_map = {}
        for r in rows:
            norm = re.sub(r'[^A-Z0-9]', '', r['plate_number'].upper())
            reg_map[norm] = r
        _REG_CACHE['timestamp'] = now
        _REG_CACHE['data'] = reg_map
        return reg_map
    except Exception as e:
        print(f"[REG-CACHE] Warning: {e}")
        return _REG_CACHE['data']
    finally:
        try:
            conn.close()
        except Exception:
            pass


def normalize_plate(plate_text):
    """Normalize plate text: uppercase, alphanumeric only."""
    plate = plate_text.upper().strip()
    plate = re.sub(r'[^A-Z0-9]', '', plate)
    return plate


def is_valid_plate(plate_text):
    """Validate extracted text from bounding box."""
    plate = normalize_plate(plate_text)
    if not (5 <= len(plate) <= 7 or (plate.isdigit() and 8 <= len(plate) <= 20)):
        return False
    if plate in NON_PLATE_WORDS:
        return False
    if len(set(plate)) <= 1:
        return False
    return True


def score_and_fix(candidate, pattern_spec):
    """Match a plate syntax pattern without changing OCR characters."""
    name, length, let_indices, dig_indices, formatter = pattern_spec
    if len(candidate) != length:
        return None, 999

    if any(not candidate[idx].isalpha() for idx in let_indices):
        return None, 999
    if any(not candidate[idx].isdigit() for idx in dig_indices):
        return None, 999
    return candidate, 0


def extract_exact_ph_plate_details(raw_string, registered_plates=None):
    """
    Preserve short OCR readings and numeric identifiers up to 20 digits.
    Extract exact known-format windows only when longer text contains noise.
    """
    if not raw_string:
        return "", "", 0.0, "", 999, False

    if registered_plates is None:
        registered_plates = get_cached_registered_plates()

    cleaned = re.sub(r'[^A-Z0-9]', '', raw_string.upper())
    if cleaned in NON_PLATE_WORDS:
        return "", "", 0.0, "", 999, False
    if not (5 <= len(cleaned) <= 7 or (cleaned.isdigit() and 8 <= len(cleaned) <= 20)):
        tokens = re.findall(r'[A-Z0-9]+', raw_string.upper())
        headers = sorted((i for i, token in enumerate(tokens) if token in NON_PLATE_WORDS),
                         key=lambda i: len(tokens[i]), reverse=True)
        for index in headers:
            if len(cleaned) <= 7 or (cleaned.isdigit() and len(cleaned) <= 20):
                break
            tokens[index] = ''
            cleaned = ''.join(tokens)

    if len(cleaned) < 4 or cleaned in NON_PLATE_WORDS:
        return "", "", 0.0, "", 999, False

    if 5 <= len(cleaned) <= 7 or (cleaned.isdigit() and 8 <= len(cleaned) <= 20):
        if len(set(cleaned)) <= 1:
            return "", "", 0.0, "", 999, False
        for pattern in PATTERNS:
            exact, _ = score_and_fix(cleaned, pattern)
            if exact:
                return pattern[4](cleaned), cleaned, 1.0, pattern[0], 0, cleaned in registered_plates
        kind = "numeric_identifier" if len(cleaned) > 7 else "alphanumeric"
        return cleaned, cleaned, 1.0, kind, 0, cleaned in registered_plates

    if cleaned.isdigit():
        return "", "", 0.0, "", 999, False

    candidates = []

    # Longer windows preserve a complete numeric identifier after header noise.
    for L in (6, 7, 11):
        for start in range(len(cleaned) - L + 1):
            sub = cleaned[start:start + L]
            for pat in PATTERNS:
                res, cost = score_and_fix(sub, pat)
                if res:
                    is_reg = res in registered_plates
                    priority = cost  # Registration classifies a plate; it must not choose its OCR text.
                    formatter = pat[4]
                    disp = formatter(res)
                    candidates.append((priority, cost, -len(sub), res, disp, pat[0], is_reg))

    if candidates:
        candidates.sort(key=lambda x: (x[0], x[1], x[2]))
        best = candidates[0]
        conf = max(0.5, 1.0 - (best[1] * 0.15))
        return best[4], best[3], conf, best[5], best[1], best[6]

    return "", "", 0.0, "", 999, False


def clean_and_select_plate_text(ocr_results):
    """
    Extract the exact Philippine license plate from EasyOCR results.
    Guarantees:
    1. Preserves short plates and numeric identifiers up to 20 digits.
    2. Strips extraneous screw marks, frame borders, timestamps, and banner words.
    3. Format matching never substitutes one alphanumeric character for another.
    4. Registration lookup classifies the extracted plate.
    """
    if not ocr_results:
        return "", "", 0.0

    blocks = []
    all_raw_tokens = []
    for item in ocr_results:
        if len(item) < 3:
            continue
        bbox, raw_text, conf = item[0], str(item[1]).strip(), float(item[2])
        if not raw_text:
            continue

        try:
            ys = [pt[1] for pt in bbox]
            xs = [pt[0] for pt in bbox]
            height = max(ys) - min(ys)
            min_x = min(xs)
            min_y = min(ys)
        except Exception:
            height = 20.0
            min_x = 0.0
            min_y = 0.0

        tokens = re.split(r'\s+', raw_text.upper())
        filtered = []
        for tok in tokens:
            clean_tok = re.sub(r'[^A-Z0-9]', '', tok)
            if clean_tok:
                filtered.append(clean_tok)
                all_raw_tokens.append(clean_tok)

        if filtered:
            line_str = " ".join(filtered)
            blocks.append({
                'text': line_str,
                'tokens': filtered,
                'conf': conf,
                'height': height,
                'min_x': min_x,
                'min_y': min_y
            })

    if not blocks:
        return "", "", 0.0

    # Build candidate strings to inspect:
    # 1. Combined single-line (left to right)
    blocks_sorted_x = sorted(blocks, key=lambda b: b['min_x'])
    combined_horizontal = " ".join(b['text'] for b in blocks_sorted_x)

    # 2. Combined multi-row (top to bottom, for motorcycle/square plates)
    blocks_sorted_y = sorted(blocks, key=lambda b: b['min_y'])
    combined_vertical = " ".join(b['text'] for b in blocks_sorted_y)

    # 3. Individual candidate strings
    candidate_strings = [
        combined_horizontal,
        combined_vertical,
        " ".join(all_raw_tokens)
    ] + all_raw_tokens

    # Remove duplicates while preserving order
    seen_strings = set()
    unique_candidates = []
    for s in candidate_strings:
        c_clean = re.sub(r'[^A-Z0-9]', '', s.upper())
        if c_clean and c_clean not in seen_strings:
            seen_strings.add(c_clean)
            unique_candidates.append(s)

    reg_cache = get_cached_registered_plates()

    best_result = None
    best_priority = 9999

    for cand_str in unique_candidates:
        disp, norm, conf, pat_name, cost, is_reg = extract_exact_ph_plate_details(cand_str, reg_cache)
        if norm:
            priority = cost
            if priority < best_priority:
                best_priority = priority
                best_result = (disp, norm, conf)

    if best_result:
        return best_result

    # Fallback if no exact Philippine format matched cleanly
    for cand_str in unique_candidates:
        clean = normalize_plate(cand_str)
        if ((5 <= len(clean) <= 7 or (clean.isdigit() and 8 <= len(clean) <= 20))
                and clean not in NON_PLATE_WORDS and len(set(clean)) > 1):
            return clean, clean, 0.5

    return "", "", 0.0


OCR_CONFUSIONS = {
    'O': '0', 'D': '0', 'Q': '0',
    'I': '1', 'T': '1', 'L': '1',
    'Z': '2',
    'S': '5',
    'B': '8', 'R': '8',
    'G': '6',
    'P': 'F',
    'U': 'V'
}


def ocr_canonical(text):
    """Map visually confusable characters to a canonical symbol for robust matching."""
    return ''.join(OCR_CONFUSIONS.get(c, c) for c in text.upper())


def plates_are_similar(plate_a, plate_b):
    """Check if two normalized plate strings represent the exact same physical vehicle plate."""
    na = normalize_plate(plate_a)
    nb = normalize_plate(plate_b)
    if na == nb:
        return True
    if len(na) != len(nb) or len(na) < 5:
        return False
    ca = ocr_canonical(na)
    cb = ocr_canonical(nb)
    return ca == cb


def similar_plate_inside(plate):
    """True if any vehicle with an open visit (inside) has a plate similar to `plate`.

    Returns True when the database cannot be checked, so we never raise an
    exit-without-entry alarm on missing information.
    """
    conn = get_db_connection()
    if not conn:
        return True
    try:
        cursor = conn.cursor()
        cursor.execute(
            """
            SELECT plate_number FROM vehicle_log_dates
            WHERE status = 'IN' AND time_out IS NULL
            ORDER BY id DESC LIMIT 500
            """
        )
        target = normalize_plate(plate)
        for (open_plate,) in cursor.fetchall():
            if plates_are_similar(target, normalize_plate(open_plate)):
                return True
        return False
    except Exception as e:
        print(f"[EXIT-ALERT] Inside check error: {e}")
        return True
    finally:
        try:
            conn.close()
        except Exception:
            pass


def is_plate_recently_logged_in_db(plate, direction='IN', cooldown_seconds=30):
    """
    Query MySQL to see if this plate's most recent log within the cooldown period
    already matches `direction` (ignoring earlier logs if the vehicle has since transitioned).
    """
    conn = get_db_connection()
    if not conn:
        return False
    try:
        cursor = conn.cursor()
        norm_target = normalize_plate(plate)
        cursor.execute(
            """
            SELECT plate_number, direction
            FROM vehicle_logs
            WHERE date_created >= NOW() - INTERVAL %s SECOND
            ORDER BY id DESC
            LIMIT 50
            """,
            (cooldown_seconds,)
        )
        recent_rows = cursor.fetchall()
        for row in recent_rows:
            logged_plate = normalize_plate(row[0])
            if plates_are_similar(norm_target, logged_plate):
                return str(row[1] or '').upper() == str(direction or '').upper()

        return False
    except Exception as e:
        print(f"[DB-COOLDOWN] Check error: {e}")
        return False
    finally:
        try:
            conn.close()
        except Exception:
            pass


# =========================================================
#  CAMERA CHANNEL (INDEPENDENT FRAME CAPTURE & BROADCAST)
# =========================================================

class CameraChannel:
    """
    Manages an independent video capture channel (Entrance or Exit).
    Supports RTSP network streams and local USB webcams.
    Decouples frame capture from HTTP streaming and AI inference.
    Broadcasts encoded MJPEG frames to all clients with zero CPU re-encoding.
    """

    def __init__(self, channel_id, name, source, direction='IN', flip_180=False, zoom_factor=1.35):
        self.channel_id = channel_id
        self.name = name
        self.source = str(source).strip()
        self.direction = direction
        self.flip_180 = bool(flip_180)
        self.zoom_factor = max(1.0, float(zoom_factor if zoom_factor is not None else 1.35))

        self.cap = None
        self.running = False
        self.connected = False
        self.capture_thread = None
        self.reconnect_requested = threading.Event()

        # Capture watchdog: each capture thread has a generation number. If a
        # thread dies or gets stuck inside the video library, a new thread
        # (next generation) takes over and the old one exits when it wakes up.
        self.capture_generation = 0
        self.last_capture_progress = time.monotonic()
        self.last_health_check = 0.0

        # Frame state (updated by capture thread at camera FPS)
        self.raw_frame = None
        self.frame_seq = 0
        self.frame_lock = threading.Lock()

        # Motion detection state (lightweight difference calculation)
        self.prev_gray_thumb = None
        self.last_motion_time = time.time()
        self.has_motion = False

        # Bounding box overlays (updated asynchronously by AI inference worker)
        self.overlay_lock = threading.Lock()
        self.active_overlays = []
        self.last_overlay_time = 0.0

        # Broadcast MJPEG cache (encodes ONCE per tick, shared across all connected browsers)
        self.broadcast_lock = threading.Lock()
        self.broadcast_cond = threading.Condition(self.broadcast_lock)
        self.latest_jpeg_bytes = None
        self.broadcast_thread = None

    def is_active(self):
        """Returns True if the camera recently had motion or active plate overlays."""
        now = time.time()
        if (now - self.last_motion_time) < 3.2:
            return True
        if self.active_overlays and (now - self.last_overlay_time) < OVERLAY_TTL_SECONDS:
            return True
        return False

    def _open_capture(self, src):
        """Open VideoCapture supporting RTSP URLs and USB webcam indexes with fast reachability check."""
        src_clean = str(src).strip()

        # Check if USB webcam index (e.g., "0", "1")
        if src_clean.isdigit():
            idx = int(src_clean)
            cap = cv2.VideoCapture(idx, cv2.CAP_DSHOW)
            if not cap.isOpened():
                cap = cv2.VideoCapture(idx)
            if cap.isOpened():
                cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                self._usb_missing_reported = None
                return cap
            if getattr(self, '_usb_missing_reported', None) != src_clean:
                self._usb_missing_reported = src_clean
                print(f"[WARNING] Camera [{self.name}] no USB camera found at number {idx}. "
                      f"Plug it in, or set this camera to another source in Camera Setup. "
                      f"Retrying every 15 seconds...", flush=True)
            return None

        # Pre-flight TCP check on RTSP port to prevent 30-second OpenCV freeze on offline camera
        m = re.search(r'@([^:/]+)(?::(\d+))?', src_clean) or re.search(r'rtsp://([^:/]+)(?::(\d+))?', src_clean)
        if m:
            host = m.group(1)
            port = int(m.group(2) or 554)
            try:
                import socket
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.settimeout(0.8)
                res = s.connect_ex((host, port))
                s.close()
                if res != 0:
                    # Camera host is offline or port is closed, skip without 30s timeout
                    return None
            except Exception:
                return None

        # Try opening RTSP stream
        cap = self._open_rtsp(src_clean)
        if cap is not None:
            return cap

        # If stream1 was requested and failed, auto-fallback to stream2 (H.264 substream)
        if "/stream1" in src_clean:
            fallback_url = src_clean.replace("/stream1", "/stream2")
            cap2 = self._open_rtsp(fallback_url)
            if cap2 is not None:
                print(f"[INFO] Camera [{self.name}] auto-switched to stable substream: {fallback_url}")
                return cap2

        return None

    def _open_rtsp(self, url):
        """Open an RTSP stream (optionally trying D3D11VA hardware decode first,
        see USE_HW_VIDEO_DECODE), falling back to normal CPU decoding."""
        base_params = [cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, 5000, cv2.CAP_PROP_READ_TIMEOUT_MSEC, 3000]
        attempts = []
        if USE_HW_VIDEO_DECODE and hasattr(cv2, "CAP_PROP_HW_ACCELERATION"):
            attempts.append(("GPU", base_params + [cv2.CAP_PROP_HW_ACCELERATION, cv2.VIDEO_ACCELERATION_ANY]))
        attempts.append(("CPU", base_params))

        for label, params in attempts:
            cap = None
            try:
                cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG, params)
                if cap.isOpened():
                    ret, frame = cap.read()
                    if ret and frame is not None:
                        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                        hw = 0
                        if label == "GPU":
                            try:
                                hw = int(cap.get(cv2.CAP_PROP_HW_ACCELERATION))
                            except Exception:
                                hw = 0
                        # OpenCV reports the *requested* accel type even if FFmpeg fell back to CPU.
                        mode = f"hardware decode requested (type {hw}; FFmpeg may fall back to CPU)" if hw > 0 else "CPU software decode"
                        print(f"[INFO] Camera [{self.name}] stream opened with {mode} at {frame.shape[1]}x{frame.shape[0]}")
                        return cap
                if cap is not None:
                    cap.release()
            except Exception as e:
                if cap is not None:
                    try:
                        cap.release()
                    except Exception:
                        pass
                print(f"[WARNING] Camera [{self.name}] {label} decode open failed: {e}")
        return None

    @staticmethod
    def fit_processing_frame(frame):
        """Limit working frames to 1080p without stretching or upscaling."""
        height, width = frame.shape[:2]
        scale = min(1.0, 1920 / width, 1080 / height)
        if scale >= 1.0:
            return frame
        return cv2.resize(frame, (max(1, round(width * scale)), max(1, round(height * scale))),
                          interpolation=cv2.INTER_AREA)

    def _read_latest_frame(self, cap):
        """Read the newest frame. If frames have piled up (the reader fell
        behind, e.g. right after switching cameras), skip the stale ones
        without converting them to images, so the picture is live again at
        once instead of slowly catching up."""
        if not (hasattr(cap, "grab") and hasattr(cap, "retrieve")):
            return cap.read()
        started = time.monotonic()
        ok = cap.grab()
        waited = time.monotonic() - started
        skipped = 0
        # A frame that was already waiting returns almost instantly; a live
        # frame has to be waited for (about 33 ms at 30 FPS).
        while ok and waited < 0.012 and skipped < 60 and self.running:
            started = time.monotonic()
            ok = cap.grab()
            waited = time.monotonic() - started
            skipped += 1
        if not ok:
            return False, None
        return cap.retrieve()

    def check_capture_health(self):
        """Watchdog (called from the broadcast loop): restart capture if its
        thread died, or has been stuck longer than any open/read timeout."""
        now = time.monotonic()
        if not self.running or now - self.last_health_check < 2.0:
            return
        self.last_health_check = now
        thread = self.capture_thread
        dead = thread is None or not thread.is_alive()
        stuck = (now - self.last_capture_progress) > 18.0
        if not (dead or stuck):
            return
        reason = "stopped unexpectedly" if dead else "stopped responding"
        print(f"[WATCHDOG] Camera [{self.name}] capture {reason}; starting a fresh connection.", flush=True)
        self.connected = False
        self.capture_generation += 1
        self.last_capture_progress = now
        self.capture_thread = threading.Thread(
            target=self.capture_loop,
            args=(self.capture_generation, True),
            name=f"Capture-{self.channel_id}-{self.capture_generation}",
            daemon=True
        )
        self.capture_thread.start()

    def capture_loop(self, generation=None, fresh=False):
        """Continuously drain frames to eliminate network buffer delay and detect motion.
        Each thread owns the stream it opened (local `cap`); a thread replaced by
        the watchdog (older generation) exits without touching the new one."""
        if generation is None:
            generation = self.capture_generation
        failures = 0
        retry_delay = 1.0
        cap = None if fresh else self.cap

        def current():
            return self.running and generation == self.capture_generation

        try:
            while current():
                self.last_capture_progress = time.monotonic()
                try:
                    if self.reconnect_requested.is_set():
                        self.reconnect_requested.clear()
                        if cap is not None:
                            cap.release()
                            cap = None
                            if self.cap is not None and current():
                                self.cap = None
                        self.connected = False
                    if cap is None or not cap.isOpened():
                        time.sleep(retry_delay)
                        if not current():
                            break
                        cap = self._open_capture(self.source)
                        self.last_capture_progress = time.monotonic()
                        if not current():
                            break
                        self.cap = cap
                        self.connected = cap is not None and cap.isOpened()
                        if not self.connected:
                            # A missing USB camera is retried slowly; network cameras quickly
                            retry_delay = 15.0 if str(self.source).strip().isdigit() else min(5.0, retry_delay + 0.5)
                        else:
                            retry_delay = 1.0
                            print(f"[INFO] Camera [{self.name} - {self.direction}] connected successfully: {self.source}")
                        continue

                    current_source = self.source
                    success, frame = self._read_latest_frame(cap)
                    if not current() or current_source != self.source or self.reconnect_requested.is_set():
                        continue

                    if not success or frame is None:
                        failures += 1
                        if failures >= 15:
                            self.connected = False
                            print(f"[WARNING] Camera [{self.name}] stream interrupted, reconnecting...")
                            failures = 0
                            try:
                                cap.release()
                            except Exception:
                                pass
                            cap = None
                            if current():
                                self.cap = None
                            time.sleep(0.5)
                        else:
                            time.sleep(0.01)
                        continue

                    failures = 0
                    self.connected = True

                    if self.flip_180 or FLIP_CAMERA_180:
                        frame = cv2.rotate(frame, cv2.ROTATE_180)

                    # Crop / Zoom into the vehicle lane (narrows wide-angle lens, focuses on car & plate)
                    if self.zoom_factor > 1.01:
                        try:
                            fh, fw = frame.shape[:2]
                            cw = int(fw / self.zoom_factor)
                            ch = int(fh / self.zoom_factor)
                            cx1 = max(0, (fw - cw) // 2)
                            cy1 = max(0, (fh - ch) // 2)
                            frame = frame[cy1:cy1 + ch, cx1:cx1 + cw]
                        except Exception:
                            pass

                    # Keep detection, OCR crops, and saved captures within a 1080p frame.
                    frame = self.fit_processing_frame(frame)

                    # Quick motion detection on downscaled 160x90 grayscale (< 1ms)
                    try:
                        small = cv2.resize(frame, (160, 90))
                        gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
                        if self.prev_gray_thumb is not None:
                            diff = cv2.absdiff(self.prev_gray_thumb, gray)
                            score = float(np.mean(diff))
                            if score > 2.2:
                                self.last_motion_time = time.time()
                                self.has_motion = True
                            else:
                                self.has_motion = False
                        self.prev_gray_thumb = gray
                    except Exception:
                        pass

                    with self.frame_lock:
                        self.raw_frame = frame
                        self.frame_seq += 1
                except Exception as e:
                    # One bad frame or library error must not end the camera thread
                    print(f"[WARNING] Camera [{self.name}] capture error ({e}); reconnecting...", flush=True)
                    self.connected = False
                    if cap is not None:
                        try:
                            cap.release()
                        except Exception:
                            pass
                        cap = None
                        if current():
                            self.cap = None
                    time.sleep(1.0)
        finally:
            # VideoCapture is owned by this thread. Never release it from an HTTP worker.
            if cap is not None:
                try:
                    cap.release()
                except Exception:
                    pass
            if self.cap is cap:
                self.cap = None
            if generation == self.capture_generation:
                self.connected = False

    def draw_overlays(self, frame, overlays):
        """Draw bounding boxes and plate labels onto a frame."""
        annotated = frame.copy()
        # Green for IN (Entrance), Bold Crimson for OUT (Exit)
        color = (0, 220, 0) if self.direction == 'IN' else (0, 50, 230)
        text_bg_color = (0, 200, 0) if self.direction == 'IN' else (0, 40, 210)

        for item in overlays:
            x1, y1, x2, y2 = item['bbox']
            label = item['label']
            # Orange warning box: exit camera sees a vehicle that was never logged IN
            warn = 'NO ENTRY' in label
            box_color = (0, 140, 255) if warn else color
            bg_color = (0, 120, 240) if warn else text_bg_color

            cv2.rectangle(annotated, (x1, y1), (x2, y2), box_color, 3 if warn else 2)

            (label_w, label_h), _ = cv2.getTextSize(
                label,
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                2
            )

            top_y = max(label_h + 10, y1)
            cv2.rectangle(
                annotated,
                (x1, top_y - label_h - 10),
                (x1 + label_w, top_y),
                bg_color,
                -1
            )

            cv2.putText(
                annotated,
                label,
                (x1, top_y - 5),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                (255, 255, 255),
                2
            )

        return annotated

    def broadcast_loop(self):
        """Paced MJPEG encoder: encodes frame ONCE at ~18 FPS and broadcasts to all HTTP clients."""
        last_seq = -1
        encode_params = [int(cv2.IMWRITE_JPEG_QUALITY), JPEG_QUALITY]
        target_delay = 1.0 / 18.0

        while self.running:
            self.check_capture_health()
            t0 = time.time()
            frame = None
            with self.frame_lock:
                if self.raw_frame is not None and self.frame_seq != last_seq:
                    frame = self.raw_frame
                    last_seq = self.frame_seq

            if frame is not None:
                with self.overlay_lock:
                    if self.active_overlays and (time.time() - self.last_overlay_time) <= OVERLAY_TTL_SECONDS:
                        overlays = list(self.active_overlays)
                    else:
                        overlays = None

                output_frame = self.draw_overlays(frame, overlays) if overlays else frame

                # Resize maintaining exact aspect ratio (max width 854px) for lightweight streaming
                h, w = output_frame.shape[:2]
                if w > 854:
                    target_w = 854
                    target_h = int(h * (854.0 / w))
                    output_frame = cv2.resize(output_frame, (target_w, target_h), interpolation=cv2.INTER_LINEAR)

                success, buffer = cv2.imencode('.jpg', output_frame, encode_params)
                if success:
                    jpeg_bytes = buffer.tobytes()
                    with self.broadcast_lock:
                        self.latest_jpeg_bytes = jpeg_bytes
                        self.broadcast_cond.notify_all()

            elapsed = time.time() - t0
            sleep_time = max(0.005, target_delay - elapsed)
            time.sleep(sleep_time)

    def generate_frames(self):
        """Generator yielding low-latency MJPEG frames for this camera."""
        while self.running:
            jpeg = None
            with self.broadcast_lock:
                self.broadcast_cond.wait(timeout=0.2)
                jpeg = self.latest_jpeg_bytes

            if jpeg is None:
                continue

            yield (
                b'--frame\r\n'
                b'Content-Type: image/jpeg\r\n\r\n'
                + jpeg
                + b'\r\n'
            )

    def start(self):
        """Start the background frame capture thread and broadcast encoder."""
        self.running = True
        self.capture_generation += 1
        self.last_capture_progress = time.monotonic()
        self.capture_thread = threading.Thread(
            target=self.capture_loop,
            args=(self.capture_generation,),
            name=f"Capture-{self.channel_id}",
            daemon=True
        )
        self.capture_thread.start()

        self.broadcast_thread = threading.Thread(
            target=self.broadcast_loop,
            name=f"Broadcast-{self.channel_id}",
            daemon=True
        )
        self.broadcast_thread.start()

    def stop(self):
        """Signal capture to stop; its owner releases the stream after read returns."""
        self.running = False
        self.connected = False
        self.reconnect_requested.set()
        with self.broadcast_lock:
            self.broadcast_cond.notify_all()

    def set_source(self, new_source, flip_180=None):
        """Update camera source dynamically without restarting the server."""
        new_source = str(new_source).strip()
        changed = False

        if new_source != self.source:
            print(f"[INFO] Updating camera [{self.name}] source: {self.source} -> {new_source}")
            self.source = new_source
            changed = True

        if flip_180 is not None and bool(flip_180) != self.flip_180:
            self.flip_180 = bool(flip_180)
            changed = True

        if changed:
            self.reconnect_requested.set()
            self.connected = False
            with self.frame_lock:
                self.raw_frame = None
            with self.broadcast_lock:
                self.latest_jpeg_bytes = None
                self.broadcast_cond.notify_all()


# =========================================================
#  DUAL CAMERA DETECTION SYSTEM
# =========================================================

class PlateDetector:
    """
    Manages dual-camera plate detection using YOLOv10 and Triple-Reader Ensemble (EasyOCR, PaddleOCR, LPRNet).
    Entrance (IN) and Exit (OUT) channels operate independently.
    Exit camera is strictly Exit Only.
    """

    def __init__(self):
        self.model = load_model()
        self.ensemble = load_ocr()
        self.ocr = self.ensemble.easyocr if self.ensemble else None
        self.inference_lock = threading.Lock()
        self.running = False

        self.detected_plates = {}  # f"{direction}:{plate}" -> last_seen datetime
        self.recent_detections = []
        self.plate_tracker = PlateTracker()
        self.last_ocr_diagnostics = {}

        self.config = {}
        self.channels = {}
        self.apply_camera_config(load_camera_config())

        self.inference_thread = None

    def _extract_ocr_from_crop(self, plate_crop):
        """
        Run Triple-Reader OCR Ensemble (EasyOCR, PaddleOCR, LPRNet) on cropped license plate.
        Arbitrates using:
        1. Majority voting (3/3 and 2/3 agreement)
        2. Character-level positional comparison & syntax enforcement (Z vs 2, D vs 0, B vs 8, S vs 5, I vs 1)
        3. Image quality & confidence checks (sharpness, blur, contrast)
        4. Python accept vs review decision
        """
        if self.ensemble is None or plate_crop is None or plate_crop.size == 0:
            return "", "", 0.0, "NEEDS_REVIEW", "NO_READER", "Ensemble not loaded"

        crop_h, crop_w = plate_crop.shape[:2]
        # Ignore crops that are too small to be legible plates (saves OCR time on background noise)
        if crop_h < 15 or crop_w < 45:
            return "", "", 0.0, "NEEDS_REVIEW", "TOO_SMALL", "Plate crop too small"

        reg_cache = get_cached_registered_plates()
        started = time.monotonic()
        result = self.ensemble.recognize_ensemble(plate_crop, registered_plates=reg_cache)
        self.last_ocr_diagnostics = {
            "updated_at": time.time(), "elapsed_ms": round((time.monotonic()-started)*1000),
            "decision": result.decision, "plate": result.final_plate, "method": result.method,
            "reason": result.review_reason, "quality": vars(result.quality),
            "readers": result.model_predictions,
            "busy": {name:self.ensemble.reader_pool.busy(name) for name in ("easyocr","paddleocr","gpt_vision")}
        }

        disp = result.display_plate
        norm = result.final_plate
        conf = result.confidence
        decision = result.decision
        method = result.method
        reason = result.review_reason

        return (disp, norm, conf, decision, method, reason)

    def detect_plates_on_frame(self, frame, channel_direction="IN", on_boxes=None):
        """Run YOLOv10 detection + Triple-Reader OCR Ensemble on a frame with directional tags."""
        plates_found = []
        overlays = []
        h, w = frame.shape[:2]

        pred_device = "cpu" if (USE_INTEL_GPU or "openvino" in str(getattr(self.model, "model_path", "")).lower()) else DEVICE
        use_half = USE_CUDA and str(pred_device).startswith("cuda")  # FP16 on the NVIDIA GPU

        results = self.model.predict(
            source=frame,
            imgsz=IMAGE_SIZE,
            conf=DETECTION_CANDIDATE_THRESHOLD,
            device=pred_device,
            half=use_half,
            verbose=False
        )

        detected_boxes = []
        for result in results:
            if result.boxes is not None and len(result.boxes) > 0:
                for box in result.boxes:
                    x1, y1, x2, y2 = map(int, box.xyxy[0].tolist())
                    conf = float(box.conf[0])
                    detected_boxes.append((x1, y1, x2, y2, conf, False))

        if not detected_boxes and AUTO_CHECK_UPSIDE_DOWN:
            rot_frame = cv2.rotate(frame, cv2.ROTATE_180)
            rot_results = self.model.predict(
                source=rot_frame,
                imgsz=IMAGE_SIZE,
                conf=DETECTION_CANDIDATE_THRESHOLD,
                device=pred_device,
                half=use_half,
                verbose=False
            )
            for result in rot_results:
                if result.boxes is not None and len(result.boxes) > 0:
                    for box in result.boxes:
                        rx1, ry1, rx2, ry2 = map(int, box.xyxy[0].tolist())
                        conf = float(box.conf[0])
                        detected_boxes.append((w - rx2, h - ry2, w - rx1, h - ry1, conf, True))

        # Publish localization immediately; OCR must not delay the visible box.
        if on_boxes and detected_boxes:
            on_boxes([{'bbox': tuple(box[:4]),
                       'label': f"[{channel_direction}] Reading plate ({box[4]:.0%})"}
                      for box in detected_boxes])

        # Prioritize highest confidence detections first and limit OCR to top 2 candidates
        detected_boxes.sort(key=lambda b: b[4], reverse=True)

        for (x1, y1, x2, y2, confidence, was_inverted) in detected_boxes[:2]:
            bw = max(1, x2 - x1)
            bh = max(1, y2 - y1)
            pad_x = int(bw * 0.08)
            pad_y = int(bh * 0.12)

            cx1 = max(0, x1 - pad_x)
            cy1 = max(0, y1 - pad_y)
            cx2 = min(w, x2 + pad_x)
            cy2 = min(h, y2 + pad_y)

            plate_crop = frame[cy1:cy2, cx1:cx2]
            if was_inverted and plate_crop.size > 0:
                plate_crop = cv2.rotate(plate_crop, cv2.ROTATE_180)

            display_text, normalized, ocr_conf, decision, method, review_reason = self._extract_ocr_from_crop(plate_crop)

            combined_conf = (confidence * 0.4) + (ocr_conf * 0.6) if ocr_conf > 0 else confidence
            if combined_conf < CONFIDENCE_THRESHOLD:
                continue

            no_entry = False
            if normalized and is_valid_plate(normalized):
                if not self._can_scan_plate(normalized, channel_direction):
                    if channel_direction != 'OUT':
                        continue  # entrance: vehicle already inside, nothing to do
                    # Exit camera: this vehicle was never logged IN. Keep it so the
                    # guard is warned (exit-without-entry alert) instead of ignoring it.
                    no_entry = True
            elif channel_direction in ('IN', 'OUT') and callable(
                getattr(self, 'can_scan_plate', None) or globals().get('should_scan_plate_on_direction')
            ):
                continue

            if no_entry:
                label = f"[OUT] NO ENTRY: {display_text or normalized}"
            elif decision == "NEEDS_REVIEW":
                label = f"[{channel_direction}] REVIEW: {display_text or 'Plate'}"
            elif display_text:
                label = f"[{channel_direction}] {display_text} ({combined_conf:.0%})"
            else:
                label = f"[{channel_direction}] Plate ({confidence:.0%})"

            overlays.append({
                'bbox': (x1, y1, x2, y2),
                'label': label
            })

            if normalized and is_valid_plate(normalized):
                plates_found.append({
                    'text': display_text,
                    'normalized': normalized,
                    'confidence': combined_conf,
                    'ocr_confidence': ocr_conf,
                    'decision': decision,
                    'method': method,
                    'review_reason': review_reason,
                    'bbox': [x1, y1, x2, y2],
                    'no_entry': no_entry,
                    '_plate_crop': plate_crop.copy()
                })
                # If primary candidate is a valid high-confidence plate, avoid redundant OCR on secondary boxes
                if ocr_conf >= 0.70:
                    break

        return overlays, plates_found

    def _can_scan_plate(self, plate_number, direction, use_cache=True):
        """Return True if plate_number is eligible to be scanned on direction ('IN', 'OUT', 'AUTO')."""
        if direction not in ('IN', 'OUT'):
            return True
        checker = getattr(self, 'can_scan_plate', None) or globals().get('should_scan_plate_on_direction')
        if not callable(checker):
            return True
        return bool(checker(plate_number, direction, use_cache=use_cache))

    def _record_direction_cooldown(self, direction, plate, now):
        """Record cooldown for `direction:plate` and clear opposite-direction cooldown for the same plate."""
        opposite = 'OUT' if direction == 'IN' else ('IN' if direction == 'OUT' else None)
        if opposite:
            for existing_key in list(self.detected_plates.keys()):
                ex_ch, existing_plate = existing_key.split(":", 1)
                if ex_ch == opposite and plates_are_similar(plate, existing_plate):
                    self.detected_plates.pop(existing_key, None)
        self.detected_plates[f'{direction}:{plate}'] = now
        cutoff = now - timedelta(minutes=10)
        self.detected_plates = {k: v for k, v in self.detected_plates.items() if v > cutoff}

    def process_frame(self, frame):
        """Run YOLOv10 detection + Triple Ensemble OCR on a single frame (used by /api/detect)."""
        overlays, plates_found = self.detect_plates_on_frame(frame, channel_direction=self.channels['entrance'].direction)
        channel = self.channels.get("entrance")
        annotated_frame = channel.draw_overlays(frame, overlays) if channel else frame
        # Internal capture pixels stay out of the public JSON response.
        public_plates = [{key: value for key, value in plate.items() if not key.startswith('_')}
                         for plate in plates_found]
        return annotated_frame, public_plates

    def handle_detection(self, plate_info, channel):
        """Handle a detected plate on a specific camera channel."""
        plate = plate_info['normalized']
        channel_key = channel.channel_id
        direction = channel.direction
        cooldown_key = f"{direction}:{plate}"
        now = datetime.now()

        decision = plate_info.get('decision', 'ACCEPT')
        method = plate_info.get('method', 'OCR')
        review_reason = plate_info.get('review_reason')

        # 0. Enforce Inside/Outside directional scan gate before cooldown or logging:
        #    - Entrance ('IN'): do NOT scan if already Inside
        #    - Exit ('OUT'): do NOT scan if already Outside
        if not self._can_scan_plate(plate, direction, use_cache=False):
            if direction == 'OUT':
                # The exit camera read a plate that never came in: warn the guard.
                try:
                    self._flag_exit_without_entry(plate_info, channel, now)
                except Exception as exc:
                    print(f"[EXIT-ALERT] Could not record exit-without-entry alert: {exc}")
            return False

        # 1. Check in-memory cooldown across recent detections on this channel/direction
        for existing_key, last_seen in list(self.detected_plates.items()):
            ex_ch, existing_plate = existing_key.split(":", 1)
            elapsed = (now - last_seen).total_seconds()
            if elapsed < PLATE_COOLDOWN_SECONDS:
                if direction != 'AUTO' and ex_ch == direction:
                    if plates_are_similar(plate, existing_plate):
                        return

        # 2. Database-backed cooldown check (prevents duplicates across restarts or prolonged gate waits)
        if direction != 'AUTO' and is_plate_recently_logged_in_db(plate, direction, cooldown_seconds=PLATE_COOLDOWN_SECONDS):
            self.detected_plates[cooldown_key] = now
            return

        # 3. Database lookup: strict registered vs visitor
        db_info = check_plate_in_database(plate)
        if (plate.isdigit() and len(plate) > 7
                and not db_info.get('registered_vehicle_id') and not db_info.get('visitor_vehicle_id')):
            db_info['official_plate'] = plate_info.get('text') or plate
        category = db_info['category']
        vehicle_type = db_info['vehicle_type']
        actual_plate = db_info.get('official_plate') or plate

        if actual_plate != plate and not self._can_scan_plate(actual_plate, direction, use_cache=False):
            return False

        # If decision is flagged as review, attach review reason to purpose
        if decision == 'NEEDS_REVIEW':
            db_info['purpose'] = f"Needs Review: {review_reason or 'Verify plate'}"


        # Visitor arrivals (IN) wait for guard form confirmation before entering MySQL;
        # cancelling the Visitor Information Input modal never writes the plate to DB.
        is_visitor_arrival = False
        if category == 'Visitor':
            if direction in ('IN', 'AUTO'):
                get_db_fn = globals().get('get_db_connection')
                has_open_fn = globals().get('has_open_visitor_visit')
                already_inside = False
                if callable(get_db_fn) and callable(has_open_fn):
                    conn_chk = get_db_fn()
                    try:
                        already_inside = bool(has_open_fn(conn_chk, actual_plate))
                    finally:
                        if conn_chk:
                            conn_chk.close()
                if direction == 'IN':
                    if already_inside:
                        return False
                    is_visitor_arrival = True
                else:
                    is_visitor_arrival = not already_inside

        if is_visitor_arrival:
            pending_plate = (
                db_info.get('official_plate')
                if db_info.get('visitor_vehicle_id')
                else (plate_info.get('text') or actual_plate)
            )
            pending_info = dict(db_info, official_plate=pending_plate)
            pending_entry = enqueue_pending_visitor(
                pending_plate,
                pending_info,
                channel_name=channel.name,
                confidence=plate_info.get('confidence', 0.0),
                cooldown_seconds=PLATE_COOLDOWN_SECONDS,
            )
            if not pending_entry:
                return False
            direction = 'IN'
            self._record_direction_cooldown(direction, plate, now)
            invalidate_fn = globals().get('invalidate_plate_state_cache')
            if callable(invalidate_fn):
                invalidate_fn(actual_plate)
            if pending_entry.get('already_queued') or pending_entry.get('suppressed'):
                return True
            # Vehicle photo for this visitor; linked to the log when the guard saves the form
            pending_id = pending_entry.get('pending_id')
            vehicle_img_path = self._capture_vehicle(plate_info, pending_id)
            crop = plate_info.get('_plate_crop')
            if crop is not None:
                try:
                    pending_detection = {
                        'plate': pending_plate,
                        'display_text': plate_info.get('text') or pending_plate,
                        'category': 'Visitor',
                        'vehicle_type': vehicle_type,
                        'direction': direction,
                        'camera': channel.name,
                        'channel': channel_key,
                        'confidence': plate_info.get('confidence', 0.0),
                        'decision': decision,
                        'method': method,
                        'review_reason': review_reason,
                        'needs_review': (decision == 'NEEDS_REVIEW'),
                        'timestamp': now.strftime('%Y-%m-%d %H:%M:%S'),
                        'pending_id': pending_id,
                        'vehicle_image': vehicle_img_path or (f"uploads/vehicle_captures/{pending_id}.jpg" if pending_id else None),
                    }
                    save_plate_capture(
                        os.path.join(BASE_DIR, 'uploads', 'plate_captures'),
                        crop,
                        pending_detection,
                    )
                except Exception as exc:
                    print(f"[WARNING] Visitor plate photo save failed: {exc}")
            print(
                f"[PENDING-VISITOR] [{channel.name} - IN] {pending_plate} ({method}) | "
                f"Awaiting guard form confirmation ({plate_info.get('confidence', 0.0):.0%})"
            )
            return True

        # Log Registered vehicles and Visitor exits (OUT) immediately to MySQL
        result = log_vehicle_detection(actual_plate, db_info, direction)
        if not result:
            return False
        direction = result['direction']
        self._record_direction_cooldown(direction, plate, now)
        invalidate_fn = globals().get('invalidate_plate_state_cache')
        if callable(invalidate_fn):
            invalidate_fn(actual_plate)
        if direction == 'OUT':
            clear_pending_fn = globals().get('clear_pending_visitor_for_plate')
            if callable(clear_pending_fn):
                clear_pending_fn(actual_plate)

        detection = {
            'plate': actual_plate,
            'display_text': plate_info.get('text') or actual_plate,
            'category': category,
            'vehicle_type': vehicle_type,
            'direction': direction,
            'camera': channel.name,
            'channel': channel_key,
            'confidence': plate_info.get('confidence', 0.0),
            'decision': decision,
            'method': method,
            'review_reason': review_reason,
            'needs_review': (decision == 'NEEDS_REVIEW'),
            'timestamp': now.strftime('%Y-%m-%d %H:%M:%S')
        }

        if result.get('log_id'):
            log_id = int(result['log_id'])
            detection['vehicle_image'] = self._capture_vehicle(
                plate_info, f"log_{log_id}",
                on_saved=lambda path, info, log_id=log_id: self._link_vehicle_photo(log_id, path))

        crop = plate_info.get('_plate_crop')
        if crop is not None:
            try:
                capture = save_plate_capture(
                    os.path.join(BASE_DIR, 'uploads', 'plate_captures'), crop, detection)
                detection['image_path'] = 'uploads/plate_captures/' + capture['image']
                detection['metadata_path'] = 'uploads/plate_captures/' + capture['metadata']
            except Exception as exc:
                # The visit is committed. A failed photo must not trigger a duplicate entry.
                detection['capture_error'] = str(exc)
                print(f"[WARNING] Plate photo save failed: {exc}")

        self.recent_detections.insert(0, detection)
        self.recent_detections = self.recent_detections[:50]

        print(
            f"[DETECTION] [{channel.name} - {direction}] {actual_plate} ({method}) | "
            f"{category} | {vehicle_type} | {plate_info.get('confidence', 0.0):.0%}"
        )
        return True

    def _flag_exit_without_entry(self, plate_info, channel, now):
        """Record a warning when a plate is read leaving but was never logged IN.

        Guards against false alarms: only confident (auto-accepted) reads, not a
        vehicle that was just logged OUT and is still in view, not a visitor
        still waiting for the guard's form, and once per plate per 10 minutes.
        """
        if plate_info.get('decision', 'ACCEPT') != 'ACCEPT':
            return None
        plate = plate_info.get('normalized') or ''
        if not plate:
            return None
        key = f"EWE:{plate}"
        seen = getattr(self, '_exit_alert_seen', None)
        if seen is None:
            seen = self._exit_alert_seen = {}
        last = seen.get(key)
        if last and (now - last).total_seconds() < EXIT_ALERT_REPEAT_SECONDS:
            return None
        seen[key] = now
        cutoff = now - timedelta(seconds=EXIT_ALERT_REPEAT_SECONDS)
        for k in [k for k, v in seen.items() if v < cutoff]:
            seen.pop(k, None)

        if is_visitor_pending_or_suppressed(plate, cooldown_seconds=EXIT_ALERT_REPEAT_SECONDS):
            return None
        # Just left a moment ago (still in front of the exit camera)
        if is_plate_recently_logged_in_db(plate, 'OUT', cooldown_seconds=EXIT_ALERT_REPEAT_SECONDS):
            return None

        # An OCR misread of a vehicle that is inside (e.g. 8 read as B) is not an exit without entry.
        if similar_plate_inside(plate):
            return None

        db_info = check_plate_in_database(plate)
        actual_plate = db_info.get('official_plate') or plate
        if actual_plate != plate:
            # A misread of a vehicle that is inside is not an exit without entry.
            if is_plate_currently_inside(actual_plate, use_cache=False) is not False:
                return None
            if is_plate_recently_logged_in_db(actual_plate, 'OUT', cooldown_seconds=EXIT_ALERT_REPEAT_SECONDS):
                return None

        conn = get_db_connection()
        if not conn:
            return None
        try:
            alert = record_exit_without_entry(
                conn, plate_info.get('text') or plate, db_info,
                camera_name=getattr(channel, 'name', None),
                confidence=plate_info.get('confidence'),
            )
            if not alert:
                return None
            alert_id = int(alert['id'])
            crop = plate_info.get('_plate_crop')
            if crop is not None:
                try:
                    capture = save_plate_capture(
                        os.path.join(BASE_DIR, 'uploads', 'exit_alerts'), crop,
                        {'alert': 'exit_without_entry', 'alert_id': alert_id, 'plate': alert['plate'],
                         'camera': getattr(channel, 'name', None),
                         'timestamp': now.strftime('%Y-%m-%d %H:%M:%S')})
                    attach_alert_image(conn, alert_id, 'plate_image', 'uploads/exit_alerts/' + capture['image'])
                except Exception as exc:
                    print(f"[EXIT-ALERT] Plate photo save failed: {exc}")
        finally:
            conn.close()

        def _link(path, info, alert_id=alert_id):
            c = get_db_connection()
            if not c:
                return
            try:
                attach_alert_image(c, alert_id, 'vehicle_image', path)
            except Exception as exc:
                print(f"[EXIT-ALERT] Could not link vehicle photo: {exc}")
            finally:
                c.close()

        self._capture_vehicle(plate_info, f"exit_alert_{alert_id}", on_saved=_link)
        print(f"[EXIT-ALERT] [{getattr(channel, 'name', 'Exit Cam')}] {alert['plate']} exited without an entry record "
              f"({alert['category']}, alert id={alert_id})")
        return alert

    def _capture_vehicle(self, plate_info, name, on_saved=None):
        """Queue a YOLOv10 vehicle photo for a successfully read plate."""
        capturer = getattr(self, 'vehicle_capturer', None)
        frame = plate_info.get('_frame')
        if capturer is None or frame is None or not name:
            return None
        try:
            return capturer.submit(frame, plate_info.get('bbox'), str(name), on_saved=on_saved)
        except Exception as exc:
            print(f"[VEHICLE] Could not queue vehicle photo: {exc}")
            return None

    def _link_vehicle_photo(self, log_id, relative_path):
        """Store the saved vehicle photo on its vehicle log row."""
        conn = get_db_connection()
        if not conn:
            return
        try:
            cursor = conn.cursor()
            cursor.execute("UPDATE vehicle_logs SET vehicle_image = %s WHERE id = %s", (relative_path, log_id))
            conn.commit()
            cursor.close()
        except Exception as exc:
            print(f"[VEHICLE] Could not link vehicle photo to log {log_id}: {exc}")
        finally:
            conn.close()

    def inference_loop(self):
        """
        High-performance AI detection worker.
        Prioritizes the camera that has active motion/vehicles so detections happen instantly.
        """
        last_processed = {"entrance": -1, "exit": -1}
        last_inference_time = {"entrance": 0.0, "exit": 0.0}

        while self.running:
            did_work = False
            now = time.time()

            # Rank channels by activity: active channels (motion or bounding box) come first
            channels_to_check = []
            for ch_id, ch in list(self.channels.items()):
                if ch_id != ch.channel_id:
                    continue  # Shared preview alias must never run inference twice.
                if ch.running:
                    active = ch.is_active()
                    channels_to_check.append((1 if active else 0, ch_id, ch))

            # Active cameras first
            channels_to_check.sort(key=lambda x: x[0], reverse=True)

            for is_act, ch_id, ch in channels_to_check:
                # Continuous low-latency pacing: 0.05s when actively tracking, 0.15s idle check
                # (Eliminates the 1.5s delay and prevents sudden bursts of detections)
                interval = INFERENCE_INTERVAL_SECONDS if is_act else 0.15
                if (now - last_inference_time.get(ch_id, 0.0)) < interval:
                    continue

                frame_to_proc = None
                with ch.frame_lock:
                    if ch.raw_frame is not None and ch.frame_seq != last_processed.get(ch_id, -1):
                        frame_to_proc = ch.raw_frame.copy()
                        last_processed[ch_id] = ch.frame_seq

                if frame_to_proc is not None:
                    did_work = True
                    last_inference_time[ch_id] = time.time()
                    try:
                        with self.inference_lock:
                            if self.channels.get(ch_id) is not ch or not ch.running:
                                continue
                            def publish_boxes(boxes):
                                with ch.overlay_lock:
                                    ch.active_overlays = boxes
                                    ch.last_overlay_time = time.time()

                            overlays, plates = self.detect_plates_on_frame(
                                frame_to_proc,
                                channel_direction=ch.direction,
                                on_boxes=publish_boxes if ch.direction == 'AUTO' else None,
                            )

                            with ch.overlay_lock:
                                if overlays:
                                    ch.active_overlays = overlays
                                    ch.last_overlay_time = time.time()
                                elif ch.direction in ('IN', 'OUT') or (time.time() - ch.last_overlay_time) > OVERLAY_TTL_SECONDS:
                                    ch.active_overlays = []

                            confirmed = self.plate_tracker.observe_frame(
                                ch_id, ch.direction, plates, visible_boxes=[o['bbox'] for o in overlays],
                                require_departure=(ch.direction == 'AUTO'))
                            for plate_info in confirmed:
                                plate_info['_frame'] = frame_to_proc  # for the vehicle photo
                                if not self.handle_detection(plate_info, ch):
                                    if self._can_scan_plate(plate_info['normalized'], ch.direction):
                                        self.plate_tracker.retry_confirmation(ch_id, ch.direction, plate_info['normalized'])
                                elif hasattr(self.plate_tracker, 'clear_plate'):
                                    self.plate_tracker.clear_plate(
                                        plate_info['normalized'], exclude_channel=ch_id, exclude_direction=ch.direction)
                    except Exception as e:
                        print(f"[WARNING] Inference loop error on {ch_id}: {e}")

                    # Break after 1 frame so other channels get equal priority when both active
                    break

            if not did_work:
                time.sleep(0.02)
            else:
                time.sleep(0.01)

    def midnight_reset_loop(self):
        """Monitors calendar date change and resets active logs at 12:00 AM sharp."""
        last_date = datetime.now().strftime("%Y-%m-%d")
        while self.running:
            try:
                time.sleep(10)
                current_date = datetime.now().strftime("%Y-%m-%d")
                if current_date != last_date:
                    print(f"[MIDNIGHT-RESET] 12:00 AM reached. Resetting active logs for new day: {current_date}...")
                    conn = get_db_connection()
                    if conn:
                        try:
                            cursor = conn.cursor()
                            cursor.execute("""
                                INSERT INTO vehicle_logs_archive
                                (
                                    id, plate_number, vehicle_type, vehicle_category,
                                    registered_vehicle_id, visitor_vehicle_id, visitor_name,
                                    purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                                    date_in, date_out, date_created, archived_at, archived_by
                                )
                                SELECT
                                    id, plate_number, vehicle_type, vehicle_category,
                                    registered_vehicle_id, visitor_vehicle_id, visitor_name,
                                    purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                                    date_in, date_out, date_created, NOW(), 'System (12:00 AM Reset)'
                                FROM vehicle_logs
                                WHERE DATE(date_created) < %s
                                ON DUPLICATE KEY UPDATE
                                    archived_at = VALUES(archived_at),
                                    archived_by = VALUES(archived_by)
                            """, (current_date,))
                            cnt = cursor.rowcount
                            cursor.execute("""
                                UPDATE vehicle_log_dates
                                SET date_out = COALESCE(date_out, DATE(time_in), %s),
                                    time_out = COALESCE(time_out, NOW()),
                                    status = 'OUT'
                                WHERE status = 'IN'
                                  AND time_out IS NULL
                                  AND (
                                      (date_in IS NOT NULL AND date_in < %s)
                                      OR (time_in IS NOT NULL AND DATE(time_in) < %s)
                                  )
                            """, (current_date, current_date, current_date))
                            cursor.execute("DELETE FROM vehicle_logs WHERE DATE(date_created) < %s", (current_date,))
                            conn.commit()
                            invalidate_plate_state_cache()
                            print(f"[MIDNIGHT-RESET] Successfully reset active logs! Archived {cnt} past log(s).")
                        except Exception as e:
                            print(f"[MIDNIGHT-RESET] Error during auto-archive: {e}")
                        finally:
                            conn.close()
                    last_date = current_date
            except Exception as e:
                print(f"[MIDNIGHT-RESET] Worker exception: {e}")

    def reload_ensemble(self):
        """Hot-reload OCR ensemble and GPTVision modules in-memory without restarting server."""
        try:
            import lpr_engine.gpu_char_extractor
            import lpr_engine.gpt_vision_reader
            import lpr_engine.quad_ensemble
            importlib.reload(lpr_engine.gpu_char_extractor)
            importlib.reload(lpr_engine.gpt_vision_reader)
            importlib.reload(lpr_engine.quad_ensemble)

            easy_reader = self.ensemble.easyocr if self.ensemble else None
            paddle_reader = self.ensemble.paddleocr if self.ensemble else None
            lpr_recognizer = self.ensemble.lprnet if self.ensemble else None

            gpt_reader = lpr_engine.gpt_vision_reader.GPTVisionReader()
            new_ensemble = lpr_engine.quad_ensemble.QuadReaderEnsemble(
                easyocr_reader=easy_reader,
                paddleocr_reader=paddle_reader,
                lprnet_recognizer=lpr_recognizer,
                gpt_vision_reader=gpt_reader,
                min_accept_confidence=0.65,
                min_sharpness=20.0,
                reader_pool=self.ensemble.reader_pool if self.ensemble else None
            )
            with self.inference_lock:
                self.ensemble = new_ensemble
                self.ocr = easy_reader
            print("[HOT-RELOAD] Successfully reloaded OCR Quad-Reader Ensemble in-memory!")
            return True
        except Exception as e:
            print(f"[HOT-RELOAD] Error reloading ensemble: {e}")
            return False

    def apply_camera_config(self, cfg):
        """Apply one shared capture or two independent captures atomically."""
        mode = cfg.get('camera_mode', 'dual')
        direction = cfg.get('single_direction', 'IN')
        if mode not in ('single', 'dual') or direction not in ('AUTO', 'IN', 'OUT'):
            raise ValueError('Choose single or dual cameras and AUTO, IN or OUT direction.')
        for key in ('entrance', 'exit'):
            if not str(cfg.get(key, {}).get('source', '')).strip():
                raise ValueError('Camera sources cannot be empty.')
        if mode == 'single':
            # The one shared camera is always on
            cfg['entrance']['enabled'] = True
        elif not cfg['entrance'].get('enabled', True) and not cfg['exit'].get('enabled', True):
            raise ValueError('At least one camera must stay on. Turn one back on, or switch to one camera.')
        if (mode == 'dual' and cfg['entrance'].get('enabled', True) and cfg['exit'].get('enabled', True)
                and cfg['entrance']['source'].strip() == cfg['exit']['source'].strip()):
            raise ValueError('Two-camera mode needs different camera sources. Disable exit until the second camera is configured.')
        with self.inference_lock:
            rebuild = (not self.channels or mode != self.config.get('camera_mode', 'dual')
                       or any(self.channels[key].enabled != cfg[key].get('enabled', True)
                              for key in (('entrance',) if mode == 'single' else ('entrance', 'exit'))))
            if rebuild:
                for ch in set(self.channels.values()):
                    ch.stop()
                channels = {}
                for key in (('entrance',) if mode == 'single' else ('entrance', 'exit')):
                    c = cfg[key]
                    channels[key] = CameraChannel(
                        channel_id=key, name=c.get('name', key.title() + ' Cam'),
                        source=c['source'], direction=direction if mode == 'single' else ('IN' if key == 'entrance' else 'OUT'),
                        flip_180=c.get('flip_180', False), zoom_factor=c.get('zoom_factor', 1.35))
                    channels[key].enabled = c.get('enabled', True)
                if mode == 'single':
                    channels['exit'] = channels['entrance']
                self.channels = channels
                if self.running:
                    for ch in set(channels.values()):
                        if ch.enabled:
                            ch.start()
            else:
                keys = ('entrance',) if mode == 'single' else ('entrance', 'exit')
                for key in keys:
                    ch, c = self.channels[key], cfg[key]
                    ch.set_source(c['source'], flip_180=c.get('flip_180', False))
                    ch.zoom_factor = c.get('zoom_factor', 1.35)
                    ch.enabled = c.get('enabled', True)
                    if self.running and ch.enabled and not ch.running:
                        ch.start()
                    elif not ch.enabled and ch.running:
                        ch.stop()
                    ch.direction = direction if mode == 'single' else ('IN' if key == 'entrance' else 'OUT')
                    with ch.overlay_lock:
                        ch.active_overlays = []
            self.config = cfg

    def reload_camera_config(self):
        """Reload camera count, manual direction, and hardware sources live."""
        try:
            self.apply_camera_config(load_camera_config())
            print('[HOT-RELOAD] Camera configuration reloaded live.')
            return True
        except Exception as e:
            print(f'[HOT-RELOAD] Error reloading camera config: {e}')
            return False

    def start(self):
        """Start both camera channels, shared AI worker, midnight monitor, and code auto-reloader."""
        self.running = True

        # YOLOv10 vehicle photos (background thread; never slows plate reading)
        self.vehicle_capturer = VehicleCapturer(
            VEHICLE_MODEL_PATH,
            os.path.join(BASE_DIR, 'uploads', 'vehicle_captures'),
            device=DEVICE if USE_CUDA else 'cpu')
        self.vehicle_capturer.start()

        for ch in set(self.channels.values()):
            if ch.enabled:
                ch.start()

        self.inference_thread = threading.Thread(
            target=self.inference_loop,
            name="AI-InferenceWorker",
            daemon=True
        )
        self.inference_thread.start()

        self.midnight_thread = threading.Thread(
            target=self.midnight_reset_loop,
            name="Midnight-ResetWorker",
            daemon=True
        )
        self.midnight_thread.start()

        # Start background code watcher for automatic hot-reloading
        self.reloader = CodeChangeReloader(detector_instance=self, base_dir=BASE_DIR)
        self.reloader.start()

        print("[INFO] Dual camera channels, AI worker, and auto-reload watcher started!")
        return True

    def stop(self):
        """Stop all channels, workers, and reloader."""
        self.running = False
        if hasattr(self, 'reloader') and self.reloader:
            self.reloader.running = False
        for ch in set(self.channels.values()):
            ch.stop()
        print("[INFO] Dual camera detection system stopped.")


# =========================================================
#  AUTOMATIC CODE & CONFIG HOT-RELOAD WATCHER
# =========================================================

class CodeChangeReloader(threading.Thread):
    """
    Watches backend code and configurations for modifications.
    When code is edited:
    1. In-memory hot-reload for OCR ensemble & modules (sub-second, 0 downtime).
    2. Live hot-reload for camera_config.json & openai_config.json.
    3. Automated graceful process restart for detect_plate_server.py changes
       so the developer NEVER needs to manually stop/restart the backend!
    """

    def __init__(self, detector_instance, base_dir, check_interval=1.0):
        super().__init__(name="CodeChangeReloader", daemon=True)
        self.detector = detector_instance
        self.base_dir = os.path.abspath(base_dir)
        self.check_interval = check_interval
        self.running = True
        self.file_mtimes = {}
        self._init_file_mtimes()

    def _get_watched_files(self):
        watched = [
            os.path.join(self.base_dir, "detect_plate_server.py"),
            os.path.join(self.base_dir, "camera_config.json"),
            os.path.join(self.base_dir, "openai_config.json"),
        ]
        lpr_dir = os.path.join(self.base_dir, "lpr_engine")
        if os.path.isdir(lpr_dir):
            for fname in os.listdir(lpr_dir):
                if fname.endswith(".py") or fname.endswith(".json"):
                    watched.append(os.path.join(lpr_dir, fname))
        return watched

    def _init_file_mtimes(self):
        for path in self._get_watched_files():
            if os.path.exists(path):
                try:
                    self.file_mtimes[path] = os.path.getmtime(path)
                except Exception:
                    pass

    def run(self):
        # Grace period on startup
        time.sleep(3.0)
        while self.running:
            try:
                time.sleep(self.check_interval)
                current_files = self._get_watched_files()
                for path in current_files:
                    if not os.path.exists(path):
                        continue
                    try:
                        mtime = os.path.getmtime(path)
                    except Exception:
                        continue
                    old_mtime = self.file_mtimes.get(path)
                    if old_mtime is not None and mtime > (old_mtime + 0.05):
                        self.file_mtimes[path] = mtime
                        time.sleep(0.3)
                        self._handle_change(path)
                        break
                    else:
                        self.file_mtimes[path] = mtime
            except Exception:
                pass

    def _handle_change(self, changed_path):
        fname = os.path.basename(changed_path)
        print(f"\n[HOT-RELOAD] >>> Detected modification in: {fname} <<<", flush=True)

        # 1. In-memory reload for lpr_engine / openai_config
        if "lpr_engine" in changed_path or fname == "openai_config.json":
            print(f"[HOT-RELOAD] Hot-reloading OCR Quad-Reader Ensemble without restarting server...", flush=True)
            if self.detector and hasattr(self.detector, 'reload_ensemble'):
                success = self.detector.reload_ensemble()
                if success:
                    print(f"[HOT-RELOAD] Success! New {fname} code is active immediately (no server restart required).\n", flush=True)
                    return

        # 2. Live reload for camera_config
        if fname == "camera_config.json":
            print(f"[HOT-RELOAD] Hot-reloading camera configuration...", flush=True)
            if self.detector and hasattr(self.detector, 'reload_camera_config'):
                self.detector.reload_camera_config()
                print(f"[HOT-RELOAD] Success! Camera configuration updated live.\n", flush=True)
                return

        # 3. Process restart for detect_plate_server.py
        print(f"[HOT-RELOAD] Core backend code changed ({fname}). Gracefully restarting server process...", flush=True)
        if self.detector:
            try:
                self.detector.stop()
            except Exception as e:
                print(f"[HOT-RELOAD] Note while stopping detector: {e}", flush=True)
        time.sleep(0.5)

        # If running under supervisor, exit with code 3 so supervisor respawns worker immediately
        if os.environ.get("DETECTOR_WORKER") == "1":
            os._exit(3)

        script_path = os.path.abspath(sys.argv[0])
        args = [sys.executable, script_path] + sys.argv[1:]
        subprocess.Popen(args)
        print(f"[HOT-RELOAD] New server process spawned with updated code. Exiting previous process...", flush=True)
        os._exit(0)


# =========================================================
#  FLASK WEB APPLICATION
# =========================================================

app = Flask(__name__)
if CORS is not None:
    CORS(app)
else:
    @app.after_request
    def add_cors_headers(response):
        response.headers['Access-Control-Allow-Origin'] = '*'
        response.headers['Access-Control-Allow-Headers'] = '*'
        response.headers['Access-Control-Allow-Methods'] = '*'
        return response

detector = None


@app.route('/')
def home():
    return """
    <h1>Dual Camera License Plate Detection Server</h1>
    <p>Endpoints:</p>
    <ul>
        <li><a href="/video/entrance">/video/entrance</a> - Live Entrance Camera (IN - 192.168.1.18)</li>
        <li><a href="/video/exit">/video/exit</a> - Live Exit Camera (OUT - 192.168.1.19) [EXIT ONLY]</li>
        <li><a href="/video">/video</a> - Legacy Entrance feed alias</li>
        <li><a href="/api/cameras">/api/cameras</a> - Camera hardware configuration (GET / POST)</li>
        <li><a href="/api/detections">/api/detections</a> - Recent detections (JSON)</li>
        <li><a href="/api/status">/api/status</a> - System & camera status (JSON)</li>
    </ul>
    """


@app.route('/video')
def video_feed_default():
    """Legacy alias: routes to Entrance Cam."""
    return video_feed_channel('entrance')


@app.route('/video/entrance')
def video_feed_entrance():
    """Live MJPEG video feed for Entrance Cam."""
    return video_feed_channel('entrance')


@app.route('/video/exit')
def video_feed_exit():
    """Live MJPEG video feed for Exit Cam (EXIT ONLY)."""
    return video_feed_channel('exit')


def video_feed_channel(channel_id):
    """Serve MJPEG stream for a specific camera channel."""
    if not detector or channel_id not in detector.channels:
        return Response(f"Camera channel '{channel_id}' not found", status=404)

    ch = detector.channels[channel_id]
    if not ch.enabled:
        return Response('Camera disabled until configured.', status=503)

    if ch.raw_frame is None and not ch.connected:
        time.sleep(1.0)
        if ch.raw_frame is None and not ch.connected:
            return Response(f"Camera [{ch.name}] is offline or connecting.", status=503)

    resp = Response(
        ch.generate_frames(),
        mimetype='multipart/x-mixed-replace; boundary=frame'
    )
    resp.headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0'
    resp.headers['Pragma'] = 'no-cache'
    resp.headers['Expires'] = '0'
    resp.headers['X-Accel-Buffering'] = 'no'
    return resp


@app.route('/api/cameras', methods=['GET', 'POST'])
def manage_cameras():
    """Get or update camera configurations live."""
    if not detector:
        return jsonify({'success': False, 'message': 'Detector not initialized'}), 500

    if request.method == 'POST':
        data = request.get_json(silent=True) or request.form.to_dict()
        cfg = json.loads(json.dumps(detector.config))
        cfg['camera_mode'] = data.get('camera_mode', cfg.get('camera_mode', 'dual'))
        cfg['single_direction'] = data.get('single_direction', cfg.get('single_direction', 'IN'))
        try:
            for key in ('entrance', 'exit'):
                if key + '_source' in data:
                    cfg[key]['source'] = str(data[key + '_source']).strip()
                if key + '_enabled' in data:
                    cfg[key]['enabled'] = str(data[key + '_enabled']).lower() in ('true', '1', 'yes')
                if key + '_zoom' in data:
                    cfg[key]['zoom_factor'] = max(1.0, min(3.0, float(data[key + '_zoom'])))
                if 'flip_' + key in data:
                    cfg[key]['flip_180'] = str(data['flip_' + key]).lower() in ('true', '1', 'yes')
            detector.apply_camera_config(cfg)
        except (ValueError, TypeError) as e:
            return jsonify({'success': False, 'message': str(e)}), 400
        save_camera_config(cfg)
        return jsonify({
            'success': True,
            'camera_mode': detector.config.get('camera_mode', 'dual'),
            'single_direction': detector.config.get('single_direction', 'IN'),
            'message': 'Camera configurations updated successfully!',
            'cameras': {
                k: {
                    'name': ch.name,
                    'source': ch.source,
                    'direction': ch.direction,
                    'enabled': ch.enabled,
                    'connected': ch.connected,
                    'flip_180': ch.flip_180,
                    'zoom_factor': ch.zoom_factor
                }
                for k, ch in detector.channels.items()
            }
        })

    # GET
    return jsonify({
        'success': True,
        'camera_mode': detector.config.get('camera_mode', 'dual'),
        'single_direction': detector.config.get('single_direction', 'IN'),
        'separate_exit_source': detector.config['exit']['source'],
        'separate_exit_zoom': detector.config['exit'].get('zoom_factor', 1.35),
        'separate_exit_enabled': detector.config['exit'].get('enabled', True),
        'cameras': {
            k: {
                'name': ch.name,
                'source': ch.source,
                'direction': ch.direction,
                'enabled': ch.enabled,
                    'connected': ch.connected,
                'flip_180': ch.flip_180,
                'zoom_factor': ch.zoom_factor,
                'stream_url': f'/video/{k}'
            }
            for k, ch in detector.channels.items()
        }
    })


@app.route('/api/detections')
def get_detections():
    """Return recent plate detections as JSON."""
    return jsonify({
        'success': True,
        'detections': detector.recent_detections
    })


@app.route('/api/refresh_registered', methods=['GET', 'POST'])
def refresh_registered():
    """Invalidate and immediately reload registered vehicles cache."""
    global _REG_CACHE
    _REG_CACHE['timestamp'] = 0.0
    _REG_CACHE['data'] = {}
    fresh = get_cached_registered_plates()
    return jsonify({
        'success': True,
        'count': len(fresh),
        'message': 'Registered plates cache refreshed'
    })


@app.route('/api/reload_code', methods=['GET', 'POST'])
@app.route('/api/reload', methods=['GET', 'POST'])
def reload_code_endpoint():
    """Trigger manual hot-reload or graceful server restart via HTTP API."""
    full_restart = request.args.get('full') in ['1', 'true', 'yes'] or request.form.get('full') in ['1', 'true', 'yes']
    if full_restart:
        def do_restart():
            time.sleep(0.5)
            if detector:
                try:
                    detector.stop()
                except Exception:
                    pass
            if os.environ.get("DETECTOR_WORKER") == "1":
                os._exit(3)
            args = [sys.executable, os.path.abspath(sys.argv[0])] + sys.argv[1:]
            subprocess.Popen(args)
            os._exit(0)
        threading.Thread(target=do_restart, daemon=True).start()
        return jsonify({
            'success': True,
            'restarted': True,
            'message': 'Server process is restarting gracefully with latest code.'
        })

    success = False
    if detector:
        success = detector.reload_ensemble()
    return jsonify({
        'success': success,
        'reloaded': True,
        'message': 'Backend OCR ensemble and modules hot-reloaded successfully without restarting server.'
    })


@app.route('/api/status')
def get_status():
    """Return system and camera channel status."""
    if not detector:
        return jsonify({'success': False, 'message': 'Detector not initialized'}), 500

    cam_status = {}
    any_connected = False
    for k, ch in detector.channels.items():
        cam_status[k] = {
            'name': ch.name,
            'source': ch.source,
            'direction': ch.direction,
            'enabled': ch.enabled,
                    'connected': ch.connected,
            'active': ch.is_active(),
            'frame_count': ch.frame_seq
        }
        if ch.connected:
            any_connected = True

    return jsonify({
        'success': True,
        'running': detector.running,
        'camera_mode': detector.config.get('camera_mode', 'dual'),
        'single_direction': detector.config.get('single_direction', 'IN'),
        'camera_connected': any_connected,
        'cameras': cam_status,
        'device': {
            'mode': f'GPU ({OV_GPU_NAME})' if USE_INTEL_GPU else ('GPU (CUDA)' if USE_CUDA else 'CPU'),
            'openvino_gpu': USE_INTEL_GPU or OV_GPU_AVAILABLE,
            'cuda_available': USE_CUDA,
            'ocr_gpu_accelerated': bool(USE_CUDA or OV_GPU_AVAILABLE),
            'device_name': OV_GPU_NAME if (USE_INTEL_GPU or OV_GPU_AVAILABLE) else (torch.cuda.get_device_name(0) if USE_CUDA else 'CPU Multi-threaded'),
            'cuda_version': torch.version.cuda if USE_CUDA else None,
            'image_size': IMAGE_SIZE,
        },
        'model_loaded': detector.model is not None,
        'ocr_loaded': detector.ocr is not None,
        'quad_ensemble': {
            'enabled': detector.ensemble is not None,
            'easyocr': detector.ensemble.easyocr is not None if detector.ensemble else False,
            'paddleocr': detector.ensemble.paddleocr is not None if detector.ensemble else False,
            'lprnet': detector.ensemble.lprnet is not None if detector.ensemble else False,
            'gpt_vision': (detector.ensemble.gpt_vision is not None and detector.ensemble.gpt_vision.is_configured()) if detector.ensemble else False,
        },
        'triple_ensemble': {
            'enabled': detector.ensemble is not None,
            'easyocr': detector.ensemble.easyocr is not None if detector.ensemble else False,
            'paddleocr': detector.ensemble.paddleocr is not None if detector.ensemble else False,
            'lprnet': detector.ensemble.lprnet is not None if detector.ensemble else False,
            'gpt_vision': (detector.ensemble.gpt_vision is not None and detector.ensemble.gpt_vision.is_configured()) if detector.ensemble else False,
        },
        'total_detections': len(detector.recent_detections),
        'ocr_diagnostics': getattr(detector, 'last_ocr_diagnostics', {}),
        'active_plates': len(detector.detected_plates)
    })


@app.route('/api/detect', methods=['POST'])
def detect_uploaded_image():
    """Detect plates in an uploaded image."""
    if 'image' not in request.files:
        return jsonify({'success': False, 'message': 'No image uploaded'}), 400

    file = request.files['image']
    img_bytes = file.read()
    nparr = np.frombuffer(img_bytes, np.uint8)
    frame = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

    if frame is None:
        return jsonify({'success': False, 'message': 'Invalid image'}), 400

    annotated, plates = detector.process_frame(frame)
    return jsonify({
        'success': True,
        'plates': plates,
        'count': len(plates)
    })


# =========================================================
#  MAIN ENTRY POINT
# =========================================================

if __name__ == '__main__':
    if os.environ.get("DETECTOR_WORKER") != "1":
        # Supervisor process: monitors worker and automatically reloads on code change (exit code 3)
        print("=" * 65)
        print("  DUAL CAMERA LICENSE PLATE DETECTION SERVER (HOT-RELOAD ENABLED)")
        print("  Auto-Reload Watcher Active: Code changes update automatically!")
        print("=" * 65)
        print()

        crash_delay = 5.0
        while True:
            env = os.environ.copy()
            env["DETECTOR_WORKER"] = "1"
            script_path = os.path.abspath(sys.argv[0])
            started_at = time.time()
            try:
                p = subprocess.Popen([sys.executable, script_path] + sys.argv[1:], env=env)
            except OSError as exc:
                # e.g. WinError 1455 (out of memory): wait and try again instead of giving up
                print(f"\n[SUPERVISOR] Could not start the server ({exc}). Retrying in {crash_delay:.0f}s...\n", flush=True)
                try:
                    time.sleep(crash_delay)
                except KeyboardInterrupt:
                    sys.exit(0)
                crash_delay = min(60.0, crash_delay * 2)
                continue
            try:
                ret = p.wait()
            except KeyboardInterrupt:
                p.terminate()
                sys.exit(0)

            if ret == 3:
                crash_delay = 5.0
                print("\n[SUPERVISOR] >>> Code change detected. Reloading server with new code... <<<\n", flush=True)
                time.sleep(0.5)
                continue
            if ret == 0:
                sys.exit(0)

            # The server crashed: restart it automatically (waiting longer after repeated crashes)
            if time.time() - started_at > 120:
                crash_delay = 5.0
            print(f"\n[SUPERVISOR] Server stopped unexpectedly (exit code {ret}). Restarting in {crash_delay:.0f}s...\n", flush=True)
            try:
                time.sleep(crash_delay)
            except KeyboardInterrupt:
                sys.exit(0)
            crash_delay = min(60.0, crash_delay * 2)

    print("=" * 65)
    print("  DUAL CAMERA LICENSE PLATE DETECTION SERVER (OPTIMIZED)")
    print("  Entrance (IN) + Exit (OUT)")
    print("  YOLOv10 + EasyOCR + MySQL | EXIT ONLY ON EXIT CAM")
    print("=" * 65)
    print()

    weights_dir = os.path.join(BASE_DIR, "weights")
    os.makedirs(weights_dir, exist_ok=True)

    detector = PlateDetector()

    if detector.start():
        print()
        print(f"[INFO] Server running at http://localhost:{SERVER_PORT}")
        print(f"[INFO] Entrance feed: http://localhost:{SERVER_PORT}/video/entrance")
        print(f"[INFO] Exit feed:     http://localhost:{SERVER_PORT}/video/exit")
        print(f"[INFO] Detections:    http://localhost:{SERVER_PORT}/api/detections")
        print(f"[INFO] Cameras API:   http://localhost:{SERVER_PORT}/api/cameras")
        print()

        app.run(
            host=SERVER_HOST,
            port=SERVER_PORT,
            debug=False,
            threaded=True
        )
    else:
        print("[ERROR] Failed to start dual camera detection system.")
        sys.exit(1)

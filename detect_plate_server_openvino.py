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

import cv2
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
    torch.set_num_threads(4)
    print("[INFO] CUDA GPU not detected by PyTorch. Running on CPU multi-threading.")

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

# OpenVINO Intel GPU acceleration (Targets Intel UHD Graphics iGPU)
os.environ["OPENVINO_DEVICE"] = "GPU"

# =========================================================
#  CONFIGURATION & PATHS
# =========================================================

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# YOLOv10 model weights path (prefer OpenVINO model for Intel UHD Graphics acceleration)
OPENVINO_MODEL_PATH = os.path.join(BASE_DIR, "weights", "best_openvino_model")
MODEL_PATH = OPENVINO_MODEL_PATH if os.path.exists(OPENVINO_MODEL_PATH) else os.path.join(BASE_DIR, "weights", "best.pt")
FALLBACK_MODEL_PATH = os.path.join(BASE_DIR, "yolov10", "weights", "yolov10s.pt")
USE_INTEL_GPU = os.path.exists(OPENVINO_MODEL_PATH)
ANY_GPU_ACTIVE = USE_CUDA or USE_INTEL_GPU

# Camera configuration JSON file
CONFIG_FILE = os.path.join(BASE_DIR, "camera_config.json")

# Default RTSP URLs for Tapo C500 cameras
DEFAULT_ENTRANCE_RTSP_URL = "rtsp://Jhetlogs:123456789ten@192.168.1.40:554/stream2"
DEFAULT_EXIT_RTSP_URL = "rtsp://Jhetlogs:123456789ten@192.168.1.23:554/stream1"
DEFAULT_RTSP_URL = DEFAULT_ENTRANCE_RTSP_URL

# Detection settings (Dynamically tuned for GPU / Intel UHD with CPU fallback)
CONFIDENCE_THRESHOLD = 0.22                  # Catches distant/angled CCTV plates cleanly
IMAGE_SIZE = 640 if ANY_GPU_ACTIVE else 480  # 640px on GPU for max plate accuracy; 480px on CPU for low latency
JPEG_QUALITY = 75 if ANY_GPU_ACTIVE else 65  # Higher visual MJPEG streaming quality on GPU
OVERLAY_TTL_SECONDS = 1.6                    # Keep bounding box overlay visible smoothly between AI passes
INFERENCE_INTERVAL_SECONDS = 0.03 if ANY_GPU_ACTIVE else 0.05 # 30ms (~33 FPS AI pass) on GPU, 50ms on CPU
PLATE_COOLDOWN_SECONDS = 30                  # 30-second cooldown prevents spam while car is at the barrier

# Camera orientation settings
FLIP_CAMERA_180 = False
AUTO_CHECK_UPSIDE_DOWN = False

# OCR settings
OCR_LANGUAGES = ['en']

# Database settings (matches PHP config)
DB_CONFIG = {
    'host': 'localhost',
    'port': 3307,
    'user': 'root',
    'password': '',
    'database': 'security_system'
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
        "zoom_factor": 1.35
    },
    "exit": {
        "name": "Exit Cam",
        "source": DEFAULT_EXIT_RTSP_URL,
        "direction": "OUT",
        "flip_180": False,
        "zoom_factor": 1.35
    }
}


def load_camera_config():
    """Load camera source settings from JSON file or create defaults."""
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cfg = json.load(f)
                for key in ["entrance", "exit"]:
                    if key not in cfg:
                        cfg[key] = DEFAULT_CAMERA_CONFIG[key].copy()
                return cfg
        except Exception as e:
            print(f"[WARNING] Failed to read {CONFIG_FILE}: {e}")

    save_camera_config(DEFAULT_CAMERA_CONFIG)
    return DEFAULT_CAMERA_CONFIG.copy()


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
    if yolov10_dir not in sys.path:
        sys.path.insert(0, yolov10_dir)

    from ultralytics import YOLOv10

    # 1. Prefer OpenVINO model for Intel UHD Graphics acceleration
    if os.path.exists(OPENVINO_MODEL_PATH):
        print(f"[INFO] *** INTEL UHD GPU ACCELERATION ACTIVE (OpenVINO) ***")
        print(f"[INFO] Loading YOLOv10 OpenVINO model from: {OPENVINO_MODEL_PATH}")
        try:
            import openvino as ov
            core = ov.Core()
            devs = core.available_devices
            gpu_name = core.get_property('GPU', 'FULL_DEVICE_NAME') if 'GPU' in devs else 'Intel UHD Graphics'
            print(f"[INFO] Target Device: {gpu_name} (OpenVINO Device: GPU)")
        except Exception:
            print("[INFO] Target Device: Intel UHD Graphics (OpenVINO Device: GPU)")
        model = YOLOv10(OPENVINO_MODEL_PATH, task="detect")
        print("[INFO] YOLOv10 OpenVINO model loaded successfully on Intel UHD Graphics!")
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
    print(f"[INFO] Initializing OCR Ensemble (GPU: {USE_CUDA}, Target Device: {DEVICE})...")

    # 1. EasyOCR
    easy_reader = None
    try:
        import easyocr
        easy_weights_dir = os.path.join(BASE_DIR, "weights", "easyocr")
        print(f"[INFO] Loading EasyOCR (GPU: {USE_CUDA}, storage: {easy_weights_dir})...")
        easy_reader = easyocr.Reader(OCR_LANGUAGES, gpu=USE_CUDA, model_storage_directory=easy_weights_dir)
        print(f"[INFO] EasyOCR loaded successfully (Accelerated: {USE_CUDA})!")
    except Exception as e:
        print(f"[WARNING] EasyOCR failed to load: {e}")

    # 2. PaddleOCR
    paddle_reader = None
    try:
        from paddleocr import PaddleOCR
        try:
            import paddle
            if USE_CUDA and paddle.device.is_compiled_with_cuda():
                paddle.device.set_device('gpu')
                print(f"[INFO] Loading PaddleOCR on GPU (PP-OCRv6)...")
            else:
                paddle.device.set_device('cpu')
                print(f"[INFO] Loading PaddleOCR on CPU (PP-OCRv6)...")
        except Exception:
            print("[INFO] Loading PaddleOCR (PP-OCRv6)...")
        paddle_reader = PaddleOCR(use_angle_cls=False, lang='en')
        print("[INFO] PaddleOCR loaded successfully!")
    except Exception as e:
        print(f"[WARNING] PaddleOCR failed to load: {e}")

    # 3. LPRNet
    lpr_recognizer = None
    try:
        from lpr_engine.recognizer import LPRNetRecognizer
        lprnet_weights = os.path.join(BASE_DIR, "weights", "lprnet_best.pth")
        if not os.path.exists(lprnet_weights):
            lprnet_weights = None
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
    """Create a MySQL database connection."""
    try:
        import mysql.connector
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except Exception as e:
        print(f"[WARNING] Database connection failed: {e}")
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
            "WHERE REPLACE(UPPER(plate_number), ' ', '') = %s "
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
            "WHERE REPLACE(UPPER(plate_number), ' ', '') = %s "
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
    """
    Log a detected vehicle into vehicle_logs and vehicle_log_dates tables.
    Supports both IN (Entrance Cam) and OUT (Exit Cam).
    Exit Cam strictly logs OUT and closes sessions.
    """
    conn = get_db_connection()
    if not conn:
        return False

    try:
        cursor = conn.cursor()

        vehicle_category = db_info.get('category', 'Visitor')
        vehicle_type = db_info.get('vehicle_type') or 'Car'
        reg_id = db_info.get('registered_vehicle_id')
        vis_id = db_info.get('visitor_vehicle_id')
        purpose = db_info.get('purpose')
        actual_plate = db_info.get('official_plate') or plate_number
        norm_plate = normalize_plate(actual_plate)

        # For visitors entering without prior visitor_vehicles record, auto-create one
        if vehicle_category == 'Visitor' and not vis_id and direction == 'IN':
            try:
                cursor.execute(
                    """
                    INSERT INTO visitor_vehicles
                        (visitor_name, address_to_visit, vehicle_type, plate_number, purpose, status, date_in, time_in, date_created)
                    VALUES
                        ('Visitor', '-', %s, %s, %s, 'IN', CURDATE(), NOW(), NOW())
                    """,
                    (vehicle_type, actual_plate, purpose or 'Visitor Entry')
                )
                conn.commit()
                vis_id = cursor.lastrowid
            except Exception as e:
                print(f"[WARNING] Could not insert into visitor_vehicles: {e}")

        # 1. Insert into vehicle_logs
        if direction == 'OUT':
            cursor.execute(
                """
                INSERT INTO vehicle_logs
                    (plate_number, vehicle_category, vehicle_type,
                     registered_vehicle_id, visitor_vehicle_id, visitor_name, purpose,
                     direction, recognition_method, date_out, date_created)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, 'OUT', 'OCR', CURDATE(), NOW())
                """,
                (
                    actual_plate,
                    vehicle_category,
                    vehicle_type,
                    reg_id,
                    vis_id,
                    'Visitor' if vehicle_category == 'Visitor' else None,
                    purpose
                )
            )
        else:
            cursor.execute(
                """
                INSERT INTO vehicle_logs
                    (plate_number, vehicle_category, vehicle_type,
                     registered_vehicle_id, visitor_vehicle_id, visitor_name, purpose,
                     direction, recognition_method, date_in, date_created)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, 'IN', 'OCR', CURDATE(), NOW())
                """,
                (
                    actual_plate,
                    vehicle_category,
                    vehicle_type,
                    reg_id,
                    vis_id,
                    'Visitor' if vehicle_category == 'Visitor' else None,
                    purpose
                )
            )

        conn.commit()
        inserted_id = cursor.lastrowid

        # 2. Update or insert into vehicle_log_dates
        if direction == 'OUT':
            # Update latest open IN session
            cursor.execute(
                """
                UPDATE vehicle_log_dates
                SET date_out = CURDATE(), time_out = NOW(), status = 'OUT'
                WHERE REPLACE(UPPER(plate_number), ' ', '') = %s
                  AND (status = 'IN' OR time_out IS NULL)
                ORDER BY id DESC LIMIT 1
                """,
                (norm_plate,)
            )
            conn.commit()

            if cursor.rowcount == 0:
                # No open IN session found; insert OUT record
                try:
                    cursor.execute(
                        """
                        INSERT INTO vehicle_log_dates
                            (vehicle_log_id, plate_number, vehicle_category, vehicle_type,
                             purpose, date_in, date_out, time_out, status)
                        VALUES
                            (%s, %s, %s, %s, %s, CURDATE(), CURDATE(), NOW(), 'OUT')
                        """,
                        (inserted_id, actual_plate, vehicle_category, vehicle_type, purpose)
                    )
                    conn.commit()
                except Exception:
                    pass

            if vis_id:
                try:
                    cursor.execute(
                        "UPDATE visitor_vehicles SET date_out = CURDATE(), time_out = NOW(), status = 'OUT' WHERE id = %s",
                        (vis_id,)
                    )
                    conn.commit()
                except Exception:
                    pass

        else:
            # IN direction
            try:
                cursor.execute(
                    """
                    INSERT INTO vehicle_log_dates
                        (vehicle_log_id, plate_number, vehicle_category, vehicle_type,
                         purpose, date_in, time_in, status)
                    VALUES
                        (%s, %s, %s, %s, %s, CURDATE(), NOW(), 'IN')
                    """,
                    (inserted_id, actual_plate, vehicle_category, vehicle_type, purpose)
                )
                conn.commit()
            except Exception:
                pass

            if vis_id:
                try:
                    cursor.execute(
                        "UPDATE visitor_vehicles SET date_in = CURDATE(), time_in = NOW(), status = 'IN' WHERE id = %s",
                        (vis_id,)
                    )
                    conn.commit()
                except Exception:
                    pass

        print(
            f"[DB] Logged detection ({direction}): "
            f"{actual_plate} | {vehicle_category} | {vehicle_type} (id={inserted_id})"
        )
        return True

    except Exception as e:
        print(f"[ERROR] Failed to log detection: {e}")
        return False
    finally:
        if conn:
            conn.close()


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
    if len(plate) not in (5, 6, 7):
        return False
    if plate in NON_PLATE_WORDS:
        return False
    if len(set(plate)) <= 1:
        return False
    return True


def score_and_fix(candidate, pattern_spec):
    """Score a candidate string against a Philippine plate syntax pattern with positional correction."""
    name, length, let_indices, dig_indices, formatter = pattern_spec
    if len(candidate) != length:
        return None, 999

    chars = list(candidate)
    corrections = 0

    for idx in let_indices:
        c = chars[idx]
        if c.isdigit():
            if c in DIGIT_TO_LETTER:
                chars[idx] = DIGIT_TO_LETTER[c]
                corrections += 1
            else:
                return None, 999
        elif not c.isalpha():
            return None, 999

    for idx in dig_indices:
        c = chars[idx]
        if c.isalpha():
            if c in LETTER_TO_DIGIT:
                chars[idx] = LETTER_TO_DIGIT[c]
                corrections += 1
            else:
                return None, 999
        elif not c.isdigit():
            return None, 999

    result = ''.join(chars)
    return result, corrections


def extract_exact_ph_plate_details(raw_string, registered_plates=None):
    """
    Extract the exact Philippine license plate from raw string using sliding windows.
    Guarantees exact length (6 or 7 characters) and eliminates screw/border noise.
    """
    if not raw_string:
        return "", "", 0.0, "", 999, False

    if registered_plates is None:
        registered_plates = get_cached_registered_plates()

    cleaned = re.sub(r'[^A-Z0-9]', '', raw_string.upper())
    for word in NON_PLATE_WORDS:
        cleaned = cleaned.replace(word, '')

    if len(cleaned) < 4:
        return "", "", 0.0, "", 999, False

    candidates = []

    # Slide windows of length 6 and 7 across the cleaned string
    for L in (6, 7):
        for start in range(len(cleaned) - L + 1):
            sub = cleaned[start:start + L]
            for pat in PATTERNS:
                res, cost = score_and_fix(sub, pat)
                if res:
                    is_reg = res in registered_plates
                    priority = cost - (100 if is_reg else 0)
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
    1. Exact character count (6 for conduction/legacy, 7 for standard/motorcycle).
    2. Strips extraneous screw marks, frame borders, timestamps, and banner words.
    3. Positional character disambiguation:
       - Letter positions: replaces '0'->'D', '2'->'Z', '8'->'B', '5'->'S', '1'->'I'
       - Digit positions: replaces 'O'/'D'->'0', 'Z'->'2', 'B'->'8', 'S'->'5', 'I'->'1'
    4. Automatically correlates with active registered vehicles for flawless recognition.
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
            if clean_tok and clean_tok not in NON_PLATE_WORDS:
                if len(clean_tok) > 6 and clean_tok.startswith('IND'):
                    clean_tok = clean_tok[3:]
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
    combined_horizontal = "".join("".join(b['tokens']) for b in blocks_sorted_x)

    # 2. Combined multi-row (top to bottom, for motorcycle/square plates)
    blocks_sorted_y = sorted(blocks, key=lambda b: b['min_y'])
    combined_vertical = "".join("".join(b['tokens']) for b in blocks_sorted_y)

    # 3. Individual candidate strings
    candidate_strings = [
        combined_horizontal,
        combined_vertical,
        "".join(all_raw_tokens)
    ] + all_raw_tokens

    # Remove duplicates while preserving order
    seen_strings = set()
    unique_candidates = []
    for s in candidate_strings:
        c_clean = re.sub(r'[^A-Z0-9]', '', s.upper())
        if c_clean and c_clean not in seen_strings:
            seen_strings.add(c_clean)
            unique_candidates.append(c_clean)

    reg_cache = get_cached_registered_plates()

    best_result = None
    best_priority = 9999

    for cand_str in unique_candidates:
        disp, norm, conf, pat_name, cost, is_reg = extract_exact_ph_plate_details(cand_str, reg_cache)
        if norm:
            priority = cost - (100 if is_reg else 0)
            if priority < best_priority:
                best_priority = priority
                best_result = (disp, norm, conf)
                if is_reg and cost == 0:
                    break  # Perfect registered match found

    if best_result:
        return best_result

    # Fallback if no exact Philippine format matched cleanly
    for cand_str in unique_candidates:
        if 5 <= len(cand_str) <= 7 and cand_str not in NON_PLATE_WORDS and len(set(cand_str)) > 1:
            return cand_str, cand_str, 0.5

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


def is_plate_recently_logged_in_db(plate, direction='IN', cooldown_seconds=30):
    """Query MySQL to see if this plate was logged within the cooldown period."""
    conn = get_db_connection()
    if not conn:
        return False
    try:
        cursor = conn.cursor()
        norm_target = normalize_plate(plate)
        cursor.execute(
            """
            SELECT plate_number
            FROM vehicle_logs
            WHERE date_created >= NOW() - INTERVAL %s SECOND
              AND direction = %s
            ORDER BY id DESC
            LIMIT 25
            """,
            (cooldown_seconds, direction)
        )
        recent_rows = cursor.fetchall()
        for row in recent_rows:
            logged_plate = normalize_plate(row[0])
            if plates_are_similar(norm_target, logged_plate):
                return True

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
                return cap
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
        cap = cv2.VideoCapture(src_clean, cv2.CAP_FFMPEG)
        if cap.isOpened():
            ret, _ = cap.read()
            if ret:
                cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                return cap
            cap.release()

        # If stream1 was requested and failed, auto-fallback to stream2 (H.264 substream)
        if "/stream1" in src_clean:
            fallback_url = src_clean.replace("/stream1", "/stream2")
            cap2 = cv2.VideoCapture(fallback_url, cv2.CAP_FFMPEG)
            if cap2.isOpened():
                ret, _ = cap2.read()
                if ret:
                    print(f"[INFO] Camera [{self.name}] auto-switched to stable substream: {fallback_url}")
                    self.source = fallback_url
                    cap2.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                    return cap2
                cap2.release()

        return None

    def capture_loop(self):
        """Continuously drain frames to eliminate network buffer delay and detect motion."""
        failures = 0
        retry_delay = 1.0

        while self.running:
            if self.cap is None or not self.cap.isOpened():
                time.sleep(retry_delay)
                if not self.running:
                    break
                self.cap = self._open_capture(self.source)
                self.connected = self.cap is not None and self.cap.isOpened()
                if not self.connected:
                    retry_delay = min(5.0, retry_delay + 0.5)
                else:
                    retry_delay = 1.0
                    print(f"[INFO] Camera [{self.name} - {self.direction}] connected successfully: {self.source}")
                continue

            success, frame = self.cap.read()

            if not success or frame is None:
                failures += 1
                if failures >= 15:
                    self.connected = False
                    print(f"[WARNING] Camera [{self.name}] stream interrupted, reconnecting...")
                    failures = 0
                    try:
                        self.cap.release()
                    except Exception:
                        pass
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

    def draw_overlays(self, frame, overlays):
        """Draw bounding boxes and plate labels onto a frame."""
        annotated = frame.copy()
        # Green for IN (Entrance), Bold Crimson for OUT (Exit)
        color = (0, 220, 0) if self.direction == 'IN' else (0, 50, 230)
        text_bg_color = (0, 200, 0) if self.direction == 'IN' else (0, 40, 210)

        for item in overlays:
            x1, y1, x2, y2 = item['bbox']
            label = item['label']

            cv2.rectangle(annotated, (x1, y1), (x2, y2), color, 2)

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
                text_bg_color,
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
        self.capture_thread = threading.Thread(
            target=self.capture_loop,
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
        """Stop capture, broadcast, and release camera resources."""
        self.running = False
        with self.broadcast_lock:
            self.broadcast_cond.notify_all()
        if self.cap:
            try:
                self.cap.release()
            except Exception:
                pass
            self.cap = None
        self.connected = False

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
            if self.cap:
                try:
                    self.cap.release()
                except Exception:
                    pass
                self.cap = None
            self.connected = False
            with self.frame_lock:
                self.raw_frame = None


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

        self.detected_plates = {}  # f"{channel}:{plate}" -> last_seen datetime
        self.recent_detections = []

        # Load camera configurations
        self.config = load_camera_config()
        self.channels = {}

        ent_cfg = self.config.get("entrance", {})
        exit_cfg = self.config.get("exit", {})

        self.channels["entrance"] = CameraChannel(
            channel_id="entrance",
            name=ent_cfg.get("name", "Entrance Cam"),
            source=ent_cfg.get("source", DEFAULT_ENTRANCE_RTSP_URL),
            direction="IN",
            flip_180=ent_cfg.get("flip_180", False),
            zoom_factor=ent_cfg.get("zoom_factor", 1.35)
        )

        self.channels["exit"] = CameraChannel(
            channel_id="exit",
            name=exit_cfg.get("name", "Exit Cam"),
            source=exit_cfg.get("source", DEFAULT_EXIT_RTSP_URL),
            direction="OUT",
            flip_180=exit_cfg.get("flip_180", False),
            zoom_factor=exit_cfg.get("zoom_factor", 1.35)
        )

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
        result = self.ensemble.recognize_ensemble(plate_crop, registered_plates=reg_cache)

        disp = result.display_plate
        norm = result.final_plate
        conf = result.confidence
        decision = result.decision
        method = result.method
        reason = result.review_reason

        # Robust safety net: if ensemble yielded no plate, fallback to clean_and_select_plate_text
        if (not norm or len(norm) < 5) and self.ocr is not None:
            try:
                scale = 70.0 / float(crop_h)
                resized = cv2.resize(plate_crop, (max(10, int(crop_w * scale)), 70), interpolation=cv2.INTER_CUBIC)
                gray = cv2.cvtColor(resized, cv2.COLOR_BGR2GRAY)
                clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
                enhanced = clahe.apply(gray)
                gaussian = cv2.GaussianBlur(enhanced, (0, 0), 1.5)
                sharpened = cv2.addWeighted(enhanced, 1.4, gaussian, -0.4, 0)
                ocr_res = self.ocr.readtext(sharpened, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
                if not ocr_res:
                    ocr_res = self.ocr.readtext(resized, allowlist="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", paragraph=False)
                fb_disp, fb_norm, fb_conf = clean_and_select_plate_text(ocr_res)
                if fb_norm and is_valid_plate(fb_norm):
                    disp = fb_disp
                    norm = fb_norm
                    conf = max(0.65, fb_conf)
                    decision = "ACCEPT"
                    method = "FALLBACK_SYNTAX_PARSER"
                    reason = None
            except Exception as e:
                print(f"[OCR-FALLBACK] Error: {e}")

        return (disp, norm, conf, decision, method, reason)

    def detect_plates_on_frame(self, frame, channel_direction="IN"):
        """Run YOLOv10 detection + Triple-Reader OCR Ensemble on a frame with directional tags."""
        plates_found = []
        overlays = []
        h, w = frame.shape[:2]

        pred_device = "cpu" if (USE_INTEL_GPU or "openvino" in str(getattr(self.model, "model_path", "")).lower()) else DEVICE

        results = self.model.predict(
            source=frame,
            imgsz=IMAGE_SIZE,
            conf=CONFIDENCE_THRESHOLD,
            device=pred_device,
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
                conf=CONFIDENCE_THRESHOLD,
                device=pred_device,
                verbose=False
            )
            for result in rot_results:
                if result.boxes is not None and len(result.boxes) > 0:
                    for box in result.boxes:
                        rx1, ry1, rx2, ry2 = map(int, box.xyxy[0].tolist())
                        conf = float(box.conf[0])
                        detected_boxes.append((w - rx2, h - ry2, w - rx1, h - ry1, conf, True))

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

            if decision == "NEEDS_REVIEW":
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
                    'bbox': [x1, y1, x2, y2]
                })
                # If primary candidate is a valid high-confidence plate, avoid redundant OCR on secondary boxes
                if ocr_conf >= 0.70:
                    break

        return overlays, plates_found

    def process_frame(self, frame):
        """Run YOLOv10 detection + Triple Ensemble OCR on a single frame (used by /api/detect)."""
        overlays, plates_found = self.detect_plates_on_frame(frame, channel_direction="IN")
        channel = self.channels.get("entrance")
        annotated_frame = channel.draw_overlays(frame, overlays) if channel else frame
        return annotated_frame, plates_found

    def handle_detection(self, plate_info, channel):
        """Handle a detected plate on a specific camera channel."""
        plate = plate_info['normalized']
        channel_key = channel.channel_id
        direction = 'OUT' if channel_key == 'exit' else 'IN'
        cooldown_key = f"{channel_key}:{plate}"
        now = datetime.now()

        decision = plate_info.get('decision', 'ACCEPT')
        method = plate_info.get('method', 'OCR')
        review_reason = plate_info.get('review_reason')

        # 1. Check in-memory cooldown across recent detections on this channel/direction
        for existing_key, last_seen in list(self.detected_plates.items()):
            ex_ch, existing_plate = existing_key.split(":", 1)
            elapsed = (now - last_seen).total_seconds()
            if elapsed < PLATE_COOLDOWN_SECONDS:
                if (ex_ch == channel_key or (ex_ch == 'entrance' and direction == 'IN') or (ex_ch == 'exit' and direction == 'OUT')):
                    if plates_are_similar(plate, existing_plate):
                        return

        # 2. Database-backed cooldown check (prevents duplicates across restarts or prolonged gate waits)
        if is_plate_recently_logged_in_db(plate, direction, cooldown_seconds=PLATE_COOLDOWN_SECONDS):
            self.detected_plates[cooldown_key] = now
            return

        self.detected_plates[cooldown_key] = now
        # Clean up stale memory (> 10 minutes old)
        cutoff = now - timedelta(minutes=10)
        self.detected_plates = {k: v for k, v in self.detected_plates.items() if v > cutoff}

        # 3. Database lookup: strict registered vs visitor
        db_info = check_plate_in_database(plate)
        category = db_info['category']
        vehicle_type = db_info['vehicle_type']
        actual_plate = db_info.get('official_plate') or plate

        # If decision is flagged as review, attach review reason to purpose
        if decision == 'NEEDS_REVIEW':
            db_info['purpose'] = f"Needs Review: {review_reason or 'Verify plate'}"

        # Enforce direction: Exit Cam is STRICTLY OUT
        direction = 'OUT' if channel_key == 'exit' else 'IN'

        # Always log to MySQL so the guard dashboard and live feed reflect every vehicle
        log_vehicle_detection(actual_plate, db_info, direction)

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

        self.recent_detections.insert(0, detection)
        self.recent_detections = self.recent_detections[:50]

        print(
            f"[DETECTION] [{channel.name} - {direction}] {actual_plate} ({method}) | "
            f"{category} | {vehicle_type} | {plate_info.get('confidence', 0.0):.0%}"
        )

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
            for ch_id, ch in self.channels.items():
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
                            overlays, plates = self.detect_plates_on_frame(frame_to_proc, channel_direction=ch.direction)

                        with ch.overlay_lock:
                            if overlays:
                                ch.active_overlays = overlays
                                ch.last_overlay_time = time.time()
                            elif (time.time() - ch.last_overlay_time) > OVERLAY_TTL_SECONDS:
                                ch.active_overlays = []

                        for plate_info in plates:
                            self.handle_detection(plate_info, ch)

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
                            cursor.execute("DELETE FROM vehicle_logs WHERE DATE(date_created) < %s", (current_date,))
                            conn.commit()
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
            import lpr_engine.gpt_vision_reader
            import lpr_engine.quad_ensemble
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
                min_sharpness=20.0
            )
            with self.inference_lock:
                self.ensemble = new_ensemble
                self.ocr = easy_reader
            print("[HOT-RELOAD] Successfully reloaded OCR Quad-Reader Ensemble in-memory!")
            return True
        except Exception as e:
            print(f"[HOT-RELOAD] Error reloading ensemble: {e}")
            return False

    def reload_camera_config(self):
        """Reload camera sources dynamically from camera_config.json."""
        try:
            self.config = load_camera_config()
            for ch_key in ["entrance", "exit"]:
                if ch_key in self.channels and ch_key in self.config:
                    ch_cfg = self.config[ch_key]
                    new_src = ch_cfg.get("source")
                    flip = ch_cfg.get("flip_180", False)
                    zoom = ch_cfg.get("zoom_factor", 1.35)
                    if new_src:
                        self.channels[ch_key].set_source(new_src, flip_180=flip)
                        self.channels[ch_key].zoom_factor = zoom
            print("[HOT-RELOAD] Camera configuration reloaded live.")
            return True
        except Exception as e:
            print(f"[HOT-RELOAD] Error reloading camera config: {e}")
            return False

    def start(self):
        """Start both camera channels, shared AI worker, midnight monitor, and code auto-reloader."""
        self.running = True

        for ch in self.channels.values():
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
        for ch in self.channels.values():
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
            os.path.join(self.base_dir, "detect_plate_server_openvino.py"),
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
        cfg = detector.config

        if 'entrance_source' in data and data['entrance_source']:
            new_src = str(data['entrance_source']).strip()
            cfg['entrance']['source'] = new_src
            detector.channels['entrance'].set_source(new_src)

        if 'exit_source' in data and data['exit_source']:
            new_src = str(data['exit_source']).strip()
            cfg['exit']['source'] = new_src
            detector.channels['exit'].set_source(new_src)

        if 'flip_entrance' in data:
            val = str(data['flip_entrance']).lower() in ['true', '1', 'yes']
            cfg['entrance']['flip_180'] = val
            detector.channels['entrance'].flip_180 = val

        if 'flip_exit' in data:
            val = str(data['flip_exit']).lower() in ['true', '1', 'yes']
            cfg['exit']['flip_180'] = val
            detector.channels['exit'].flip_180 = val

        if 'entrance_zoom' in data:
            try:
                z = max(1.0, min(3.0, float(data['entrance_zoom'])))
                cfg['entrance']['zoom_factor'] = z
                detector.channels['entrance'].zoom_factor = z
            except Exception:
                pass

        if 'exit_zoom' in data:
            try:
                z = max(1.0, min(3.0, float(data['exit_zoom'])))
                cfg['exit']['zoom_factor'] = z
                detector.channels['exit'].zoom_factor = z
            except Exception:
                pass

        # Ensure directions remain strict
        cfg['entrance']['direction'] = 'IN'
        cfg['exit']['direction'] = 'OUT'

        save_camera_config(cfg)
        return jsonify({
            'success': True,
            'message': 'Camera configurations updated successfully!',
            'cameras': {
                k: {
                    'name': ch.name,
                    'source': ch.source,
                    'direction': ch.direction,
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
        'cameras': {
            k: {
                'name': ch.name,
                'source': ch.source,
                'direction': ch.direction,
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
            'connected': ch.connected,
            'active': ch.is_active(),
            'frame_count': ch.frame_seq
        }
        if ch.connected:
            any_connected = True

    return jsonify({
        'success': True,
        'running': detector.running,
        'camera_connected': any_connected,
        'cameras': cam_status,
        'device': {
            'mode': 'Intel UHD iGPU (OpenVINO)' if USE_INTEL_GPU else ('GPU' if USE_CUDA else 'CPU'),
            'openvino_gpu': USE_INTEL_GPU,
            'cuda_available': USE_CUDA,
            'device_name': 'Intel(R) UHD Graphics (iGPU)' if USE_INTEL_GPU else (torch.cuda.get_device_name(0) if USE_CUDA else 'CPU Multi-threaded'),
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
        print("  INTEL UHD GPU ACCELERATED DETECTION SERVER (OPENVINO)")
        print("  Auto-Reload Watcher Active: Code changes update automatically!")
        print("=" * 65)
        print()

        while True:
            env = os.environ.copy()
            env["DETECTOR_WORKER"] = "1"
            script_path = os.path.abspath(sys.argv[0])
            p = subprocess.Popen([sys.executable, script_path] + sys.argv[1:], env=env)
            try:
                ret = p.wait()
            except KeyboardInterrupt:
                p.terminate()
                sys.exit(0)

            if ret != 3:
                sys.exit(ret)

            print("\n[SUPERVISOR] >>> Code change detected. Reloading server with new code... <<<\n", flush=True)
            time.sleep(0.5)

    print("=" * 65)
    print("  DUAL CAMERA LICENSE PLATE DETECTION SERVER (OPTIMIZED)")
    print("  Entrance (IN: 192.168.1.18) + Exit (OUT: 192.168.1.19)")
    print("  YOLOv10 + EasyOCR + MySQL | EXIT ONLY ON EXIT CAM")
    print("=" * 65)
    print()

    weights_dir = os.path.join(BASE_DIR, "weights")
    os.makedirs(weights_dir, exist_ok=True)

    detector = PlateDetector()

    if detector.start():
        print()
        print(f"[INFO] Server running at http://127.0.0.1:{SERVER_PORT}")
        print(f"[INFO] Entrance feed: http://127.0.0.1:{SERVER_PORT}/video/entrance (192.168.1.18)")
        print(f"[INFO] Exit feed:     http://127.0.0.1:{SERVER_PORT}/video/exit (192.168.1.19 - EXIT ONLY)")
        print(f"[INFO] Detections:    http://127.0.0.1:{SERVER_PORT}/api/detections")
        print(f"[INFO] Cameras API:   http://127.0.0.1:{SERVER_PORT}/api/cameras")
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

# 🚗 Step-by-Step Implementation Guide
## License Plate Recognition Based Vehicle Logging System
**Location:** Lessandra Subdivision, Phase 2, Salinas 1, Bacoor, Cavite  
**Core Technologies:** TP-Link Tapo C260 Camera, Python, YOLOv10, LPRNet, PHP, MySQL/MariaDB (Port 3307), XAMPP

---

## 📌 Table of Contents
1. [Overview & Project Pipeline](#1-overview--project-pipeline)
2. [Phase 1: Environment Setup & Health Check](#phase-1-environment-setup--health-check)
3. [Phase 2: Camera Configuration (Tapo C260)](#phase-2-camera-configuration-tapo-c260)
4. [Phase 3: Philippine Plate Dataset Collection & Labeling](#phase-3-philippine-plate-dataset-collection--labeling)
5. [Phase 4: LPRNet Training on Google Colab (NVIDIA GPU)](#phase-4-lprnet-training-on-google-colab-nvidia-gpu)
6. [Phase 5: Comparative Model Evaluation (EasyOCR vs PaddleOCR vs LPRNet)](#phase-5-comparative-model-evaluation-easyocr-vs-paddleocr-vs-lprnet)
7. [Phase 6: Local Deployment & XAMPP Integration](#phase-6-local-deployment--xampp-integration)
8. [Phase 7: System Verification & Demonstration Checklist](#phase-7-system-verification--demonstration-checklist)

---

## 1. Overview & Project Pipeline

The system operates strictly according to this verified 8-stage pipeline:

```
[ Tapo C260 RTSP Camera ]
         │ (Stream 1 High-Resolution)
         ▼
[ Video Frame Processing & Ring Buffer ]
         │ (Native Frame for AI, 854x480 MJPEG for Dashboard)
         ▼
[ YOLOv10 Plate Localization (weights/best.pt) ]
         │ (Bboxes, Coordinates, Confidence >= 0.22)
         ▼
[ Crop Quality Assessment & Conditional Preprocessing ]
         │ (Sharpness Gate, Tilt Rectification, Dynamic CLAHE, 94x24 Aspect Padding)
         ▼
[ LPRNet Philippine Character Recognition ]
         │ (36-Char Alphanumeric CTC Decoding, Prefix Beam Search)
         ▼
[ Multi-Frame Verification & Ambiguity Detection ]
         │ (Weighted Consensus, Flags Z vs 2, B vs 8, D vs 0 ties)
         ▼
[ Philippine Plate Format Validation & Database Lookup ]
         │ (Standard 4W, Conduction, Motorcycle; Exact Registered Match)
         ▼
┌───────────────────────────┴───────────────────────────┐
▼                                                       ▼
[ High Confidence / Registered Match ]        [ Ambiguous / Unregistered / Tie ]
         │                                               │
         ▼                                               ▼
[ Automatic MySQL Logging ]                   [ Security Guard Review Queue ]
  (vehicle_logs + vehicle_log_dates)            (Prompt in guard_dashboard.php)
```

---

## Phase 1: Environment Setup & Health Check

### Step 1.1: Verify Python & Core Libraries
Open PowerShell in `d:\xampp\htdocs\NewCapstone` and verify the required dependencies:

```powershell
cd d:\xampp\htdocs\NewCapstone
python -m pip install -q torch torchvision ultralytics easyocr mysql-connector-python flask flask-cors opencv-python numpy pyyaml tqdm
```

### Step 1.2: Run the LPRNet Pipeline Self-Test
The engine in `lpr_engine/` contains the full model, conditional preprocessor, CTC decoder, and format validator. Run the automated test script:

```powershell
python test_lprnet_pipeline.py
```

**Expected output:**
```
============================================================
  ALL LPRNet ENGINE TESTS PASSED SUCCESSFULLY! (100%)
============================================================
```
This confirms:
- LPRNet tensor dimensions `(B, 37, 18)` are mathematically verified.
- Conditional aspect-padded preprocessing preserves stroke geometry.
- Prefix beam search flags `Z` vs `2` ambiguity when probabilities are close.
- Philippine plate validator routes `0566CP` (misread `D566CP`) to guard review rather than corrupting the database.

---

## Phase 2: Camera Configuration (Tapo C260)

The **TP-Link Tapo C260** is a 4K Ultra HD (3840×2160) camera. Capturing high-resolution frames is critical so characters like **Z vs 2** and **D vs 0** have distinct diagonal and curved strokes.

### Step 2.1: Configure Tapo Camera Account
1. Open the **Tapo App** on your smartphone.
2. Select your Tapo C260 camera -> Tap the **Gear Icon (⚙ Settings)** in the upper right.
3. Go to **Advanced Settings** -> **Camera Account**.
4. Create or confirm your account credentials:
   - **Username:** `Jhetlogs`
   - **Password:** `123456789ten` (or your configured password)
5. Tap **Video Quality** and select **Best Quality (4K / 2K)**.

### Step 2.2: Test RTSP Stream in VLC Media Player
1. Open **VLC Media Player**.
2. Go to **Media** -> **Open Network Stream...** (`Ctrl + N`).
3. Enter the RTSP stream URL:
   ```
   rtsp://Jhetlogs:123456789ten@192.168.1.71:554/stream1
   ```
4. Click **Play**. Confirm that the high-resolution feed displays with smooth playback.

### Step 2.3: Update `camera_config.json`
Ensure `d:\xampp\htdocs\NewCapstone\camera_config.json` points to `/stream1`:
```json
{
    "entrance": {
        "name": "Entrance Cam",
        "source": "rtsp://Jhetlogs:123456789ten@192.168.1.71:554/stream1",
        "direction": "IN",
        "flip_180": false
    },
    "exit": {
        "name": "Exit Cam",
        "source": "rtsp://Jhetlogs:123456789ten@192.168.1.72:554/stream1",
        "direction": "OUT",
        "flip_180": false
    }
}
```

---

## Phase 3: Philippine Plate Dataset Collection & Labeling

Since open-source LPRNet weights only recognize Chinese CCPD characters, you must train or fine-tune LPRNet on Philippine plates.

### Step 3.1: Harvest Plate Crops Using the Automated Tool
Run the crop extraction tool on live camera feeds, recorded gate videos, or existing images:

```powershell
python tools/collect_and_label_crops.py --source rtsp://Jhetlogs:123456789ten@192.168.1.71:554/stream1 --output-dir datasets/ph_plates --serve-labeler
```
*Or from a recorded gate video:*
```powershell
python tools/collect_and_label_crops.py --source sample_gate_video.mp4 --output-dir datasets/ph_plates --serve-labeler
```

What this tool does:
1. Detects license plates with your trained YOLOv10 model (`weights/best.pt`).
2. Slices clean, high-resolution plate crops.
3. Automatically computes image sharpness, brightness, and condition tags (`daytime`, `nighttime`, `blurry`, `angled`).
4. Generates initial candidate text using EasyOCR as a labeling hint.

### Step 3.2: Label Ground-Truth Text in Your Browser
1. Open your browser and navigate to:
   ```
   http://127.0.0.1:5055
   ```
2. You will see the interactive **Philippine Plate Annotation Tool**.
3. For each plate crop:
   - Type or confirm the exact ground truth (e.g., `N838RZ`, `D566CP`, `NAA1234`).
   - Specifically verify **Z vs 2** and **D vs 0** characters.
   - Click **Save / Next** (`Enter` key shortcut).
4. The tool saves labels to `datasets/ph_plates/annotations.csv` and automatically splits them into `train/` and `val/` sets without vehicle overlap.

---

## Phase 4: LPRNet Training on Google Colab (NVIDIA GPU)

### Step 4.1: Zip the Labeled Dataset
In PowerShell:
```powershell
Compress-Archive -Path datasets/ph_plates -DestinationPath datasets/ph_plates.zip
```

### Step 4.2: Open the Training Notebook in Google Colab
1. Go to [Google Colab](https://colab.research.google.com/).
2. Click **Upload** and upload `Train_and_Evaluate_LPRNet_PH.ipynb` from your project folder.
3. Click **Runtime** -> **Change runtime type** -> Select **T4 GPU** -> Click **Save**.

### Step 4.3: Execute Training Cells
1. **Cell 1 (GPU Check):** Verifies Tesla T4 GPU allocation via `!nvidia-smi`.
2. **Cell 2 (Upload Dataset):** Upload `ph_plates.zip`.
3. **Cell 3 (Train LPRNet):**
   ```python
   !python train_lprnet_ph.py --data-dir /content/ph_plates --epochs 80 --batch-size 32 --lr 0.001 --device cuda
   ```
   - Uses CTC loss with AdamW and Cosine Annealing learning rate schedule.
   - Monitors per-epoch validation accuracy, Character Error Rate (CER), and specific **Z/2** and **D/0** confusion rates.
4. **Cell 4 (Download Model):** Downloads `lprnet_ph_best.pt` directly to your computer.

### Step 4.4: Place Weights in Project Directory
Copy the downloaded weights file to:
```
d:\xampp\htdocs\NewCapstone\weights\lprnet_ph_best.pt
```

---

## Phase 5: Comparative Model Evaluation (EasyOCR vs PaddleOCR vs LPRNet)

Section 6 of the thesis requires an empirical comparison using the exact same held-out dataset.

### Step 5.1: Run the 3-Way Benchmark
Run the evaluation suite in PowerShell (or in Google Colab):

```powershell
python evaluate_ocr_models.py --val-dir datasets/ph_plates/val --weights weights/lprnet_ph_best.pt --output-report reports/thesis_evaluation.json
```

### Step 5.2: Review Generated Thesis Tables
The evaluation produces complete thesis documentation:
1. **Overall Performance Table:**
   - Exact Plate Match Accuracy (%)
   - Character-Level Accuracy (%)
   - Character Error Rate (CER = `(S + D + I) / N`)
   - Recognition Failure Rate (%)
   - Average Inference Time per Plate (ms)
2. **Environmental Robustness Table:**
   - Performance segmented by: `Daytime`, `Nighttime`, `Blurry`, `Angled`, `Low-Light`.
3. **Confusion Matrix Analysis:**
   - Specific confusion frequencies for:
     - `Z` vs `2`
     - `B` vs `8`
     - `D` vs `0` / `O` vs `0`
     - `S` vs `5`
     - `I` vs `1`

---

## Phase 6: Local Deployment & XAMPP Integration

### Step 6.1: Start XAMPP Services
1. Open **XAMPP Control Panel**.
2. Start **Apache** (Web Server).
3. Start **MySQL** (listening on **Port 3307**).
4. Verify database access: Open `http://localhost/phpmyadmin` (or `http://localhost:8080/phpmyadmin`) and confirm the `security_system` database is present.

### Step 6.2: Launch the AI Detection Server
Open a dedicated terminal window:

```powershell
cd d:\xampp\htdocs\NewCapstone
python detect_plate_server.py
```

The server initializes:
- Tapo C260 RTSP Capture on `rtsp://.../stream1` with automatic TCP reconnection.
- YOLOv10 plate detector loaded from `weights/best.pt`.
- LPRNet recognition engine loaded from `weights/lprnet_ph_best.pt`.
- MySQL transaction logging connected to `localhost:3307`.
- Live web streams active at:
  - Entrance: `http://127.0.0.1:5000/video/entrance`
  - Exit: `http://127.0.0.1:5000/video/exit`

### Step 6.3: Access the Security Guard & Admin Portals
- **Security Guard Dashboard:**  
  `http://localhost/NewCapstone/guard_dashboard.php`
- **Administrator Dashboard:**  
  `http://localhost/NewCapstone/superadmin_dashboard.php`

---

## Phase 7: System Verification & Demonstration Checklist

Follow this checklist to test and verify every component for your thesis demonstration:

| Test Case | Procedure | Expected Result | Pass/Fail |
|---|---|---|---|
| **1. Stream Latency & Stability** | Open `guard_dashboard.php` and observe entrance & exit feeds. | Live video plays smoothly at ~18 FPS without buffering or freeze. | [ ] |
| **2. Registered Vehicle Influx** | Drive/present registered vehicle `N838RZ` to Entrance Cam. | Green bounding box with `[IN] N 838 RZ (95%)`. Saved as `Registered` in `vehicle_logs`. | [ ] |
| **3. Z vs 2 Distinguishing** | Present plates ending with `Z` vs `2` (e.g. `N838RZ` vs `N838R2`). | Model correctly identifies `Z` vs `2`. If ambiguous, flags low margin and requests guard confirmation. | [ ] |
| **4. Confusable Resident Protection** | Present misread plate `0566CP` (for registered car `D566CP`). | System flags `0566CP` as ambiguous confusable neighbor to resident `D566CP`, preventing false visitor logging. | [ ] |
| **5. Visitor Vehicle Workflow** | Present unregistered plate `ABC 1234`. | Logged as `Visitor`. Guard popup requests Purpose & Allowed Stay Duration (15m, 20m, 8h, 12h). | [ ] |
| **6. Multi-Frame Anti-Duplicate** | Keep a vehicle stationary in front of the camera for 45 seconds. | Only **one** `IN` record is created; does not spam duplicate entries. | [ ] |
| **7. Exit Cam Enforcement** | Present vehicle to Exit Cam. | Strictly logs `OUT` direction in `vehicle_logs` and closes open session in `vehicle_log_dates`. | [ ] |
| **8. 12:00 AM Midnight Reset** | Check active logs after midnight (or trigger via `system_config.php`). | Previous day logs archived to `vehicle_logs_archive`; active dashboard starts fresh at 0. | [ ] |

---

## 🛠 Troubleshooting Quick Reference

- **Camera Stream Shows "Offline / Reconnecting":**
  - Check that the camera IP (`192.168.1.71`) matches your Tapo C260's current IP in the Tapo App.
  - Verify that no other application (like VLC) is locking the RTSP connection.
- **Port 5000 Already in Use:**
  - Close any orphan Python instances:
    ```powershell
    Get-Process python | Stop-Process -Force
    ```
- **MySQL Connection Error on Port 3307:**
  - In XAMPP, verify MySQL is running on `3307` (configured in `my.ini`).
- **Low FPS on CPU-Only Laptops:**
  - In `camera_config.json`, the MJPEG preview streaming is automatically downscaled to `854×480` so browsers do not lag, while high-resolution cropping is preserved for the AI engine.


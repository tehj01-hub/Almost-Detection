# 🚗 How to Connect YOLOv10 License Plate Detection to Your Capstone Project

This guide provides step-by-step instructions for connecting your trained **YOLOv10 AI Model**, your **Tapo C500 CCTV Camera**, and your **PHP/MySQL Web Application**.

---

## 📐 System Architecture Overview

```mermaid
flowchart LR
    A["📹 Tapo C500 CCTV\n(RTSP Stream)"] --> B["🐍 Python AI Server\n(detect_plate_server.py)"]
    B -->|1. Detects Plate| C["🤖 YOLOv10 (best.pt)"]
    C -->|2. Reads Text| D["🔍 EasyOCR"]
    D -->|3. Auto-Insert Logs| E["🗄️ MySQL Database\n(Port 3307: security_system)"]
    B -->|4. Live MJPEG Feed| F["🖥️ CCTV Monitor\n(cctv.html)"]
    E -->|5. Real-Time Stats| G["🛡️ Security Guard Dashboard\n(guard_dashboard.php)"]
```

1. **Camera Feed**: `detect_plate_server.py` pulls live RTSP frames from your Tapo C500.
2. **YOLOv10 AI**: Detects license plate bounding boxes in real-time.
3. **EasyOCR Engine**: Crops the plate and reads alphanumeric characters (e.g., `ABC 1234`).
4. **Automatic Logging**: Checks if plate exists in `registered_vehicles` or `visitor_vehicles`, then records entry/exit in `vehicle_logs`.
5. **Web Interface**: `cctv.html` shows live video with boxes, and `guard_dashboard.php` automatically displays updated stats.

---

## ✅ Prerequisites Checklist

Before running the system, ensure the following:
- [x] Python dependencies installed (`flask`, `torch`, `ultralytics`, `easyocr`, `opencv-python`, `mysql-connector-python`).
- [ ] XAMPP is running with **Apache** and **MySQL (Port 3307)** active.
- [ ] Trained weights file `best.pt` downloaded from Google Colab / Google Drive.
- [ ] CCTV camera (Tapo C500) powered on and on the same Wi-Fi/local network (`192.168.1.17`).

---

## 🛠️ Step-by-Step Connection Guide

### Step 1: Put Your Trained Model in the `weights` Folder

1. Download **`best.pt`** from your Google Drive:
   - Path in Drive: `My Drive > YOLOv10_License_Plate_Checkpoint > best.pt`
2. Move `best.pt` into your project's `weights/` directory:
   ```
   d:\xampp\htdocs\NewCapstone\weights\best.pt
   ```

---

### Step 2: Verify Detection Locally (Quick Test)

Before connecting to the live camera, verify that your AI model and OCR read plates properly:

1. Open your VS Code terminal (or PowerShell).
2. Run the test script:
   ```powershell
   python tests/test_plate_detection.py
   ```
3. You will see:
   - Plate detection bounding box coordinates.
   - YOLO confidence percentage (e.g. `92%`).
   
   - OCR plate text (e.g. `ABC 1234`).
   - An output image at `tests/artifacts/test_result.jpg` showing the visual detection box.

---

### Step 3: Check Configuration in `detect_plate_server.py`

Open [`detect_plate_server.py`](../../detect_plate_server.py) and confirm lines 30–75 match your setup:

```python
# 1. CCTV RTSP URL (Tapo C500)
RTSP_URL = "rtsp://Jhetlogs:12345678I@192.168.1.17:554/stream1"

# 2. Database connection (Port 3307 matches your XAMPP configuration)
DB_CONFIG = {
    'host': 'localhost',
    'port': 3307,
    'user': 'root',
    'password': '',
    'database': 'security_system'
}

# 3. Cooldown setting (seconds before logging the same vehicle again)
PLATE_COOLDOWN_SECONDS = 60
```

> **Note**: If testing without the physical camera at home, you can change `RTSP_URL = 0` to use your computer webcam!

---

### Step 4: Start the YOLOv10 AI Detection Server

In your VS Code terminal, run:

```powershell
python detect_plate_server.py
```

When started, the terminal will display:
```
============================================================
  Starting License Plate Detection Server
============================================================
[INFO] Loading YOLOv10 model from: ...\weights\best.pt
[INFO] Model loaded successfully!
[INFO] Loading EasyOCR engine...
[INFO] EasyOCR loaded successfully!
[INFO] Connecting to camera: rtsp://...
 * Running on http://127.0.0.1:5000
```

Keep this terminal window running in the background.

---

### Step 5: View Live Detections on the Web Pages

#### A. CCTV Monitoring Page (`cctv.html`)
1. Open your browser and go to:
   ```
   http://localhost/NewCapstone/cctv.html
   ```
2. You will see:
   - **Left side:** Live video stream with green bounding boxes and confidence tags over detected plates.
   - **Right side:** Live detection log showing Plate Number, Registered/Visitor badge, and timestamps.
   - **Status Bar:** Green indicator showing server health and camera connection status.

#### B. Security Guard Dashboard (`guard_dashboard.php`)
1. In your browser, log in as Security Guard:
   ```
   http://localhost/NewCapstone/guard_dashboard.php
   ```
2. As vehicles pass the camera, the Python server logs them into the `vehicle_logs` table.
3. Refresh the guard dashboard to see:
   - Total Vehicles count updated.
   - Registered vs Visitor vehicle charts updated.
   - Recent vehicle activity table showing newly logged plate numbers.

---

## ⚙️ How the Database Integration Works

When a license plate is detected and read:

1. **Clean Plate String**: The server removes spaces and special characters (`ABC-1234` → `ABC1234`).
2. **Category Lookup**:
   - Checks `registered_vehicles` table: if found, assigns **"Registered"** category and grabs the registered vehicle type.
   - Checks `visitor_vehicles` table: if found, assigns **"Visitor"** category.
   - If not found in either table: automatically classifies as **"Visitor"**.
3. **Direction Determination**:
   - If the vehicle's last movement was `IN`, logs as `OUT`.
   - Otherwise, logs as `IN`.
4. **Cooldown Prevention**:
   - Avoids logging the exact same plate dozens of times while the car is parked in front of the gate (default: 60-second cooldown).

---

## ❓ Troubleshooting & FAQs

### 1. Camera stream is black or says "Camera Offline"
- Confirm your PC and Tapo C500 are on the same local network subnet (`192.168.1.x`).
- Verify credentials in `detect_plate_server.py` (`Jhetlogs:12345678I`).
- Test your RTSP stream in VLC Player (`Media -> Open Network Stream`).
- For quick testing without camera, set `RTSP_URL = 0` (webcam) or pass a video file path: `RTSP_URL = "test_video.mp4"`.

### 2. Plate detected, but OCR text is empty or inaccurate
- Ensure the CCTV camera is positioned at gate level facing incoming vehicles.
- Adjust `CONFIDENCE_THRESHOLD = 0.25` in `detect_plate_server.py` if plates are further away.
- Adequate lighting or night vision IR on the Tapo C500 is needed for crisp plate characters at night.

### 3. Database connection error (`Can't connect to MySQL server`)
- Open XAMPP Control Panel and check that MySQL is started on port **3307**.
- If your MySQL is using the default port **3306**, change `'port': 3306` in `DB_CONFIG` inside `detect_plate_server.py`.

---

## 📁 Key Files Reference

| File | Role |
|------|------|
| [`weights/best.pt`](../../weights/best.pt) | Your trained YOLOv10 license plate weights |
| [`test_plate_detection.py`](../../tests/test_plate_detection.py) | Standalone test script for single images |
| [`detect_plate_server.py`](../../detect_plate_server.py) | Full backend: Camera + AI + OCR + MySQL |
| [`cctv.html`](../../cctv.html) | Live web interface with video + detection logs |
| [`guard_dashboard.php`](../../guard_dashboard.php) | Security guard web dashboard |

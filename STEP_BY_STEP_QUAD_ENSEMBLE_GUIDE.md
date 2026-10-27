# Step-by-Step Implementation Guide: 4-Reader OCR Ensemble (EasyOCR, PaddleOCR, LPRNet, and GPT Vision)

**Project Title:**  
*License Plate Recognition Based Vehicle Logging System for Lessandra Subdivision, Phase 2, Salinas 1, Bacoor, Cavite*

---

## 1. Architecture Overview

To achieve maximum accuracy and eliminate confusion between similar characters (such as **Z vs. 2**, **D vs. 0**, **B vs. 8**, and **S vs. 5**), the system combines **four diverse OCR readers** into a unified voting and character-level arbitration ensemble:

```
                  ┌─────────────────────────────────────────────────────────┐
                  │          Tapo C500 Surveillance Camera (RTSP)           │
                  └────────────────────────────┬────────────────────────────┘
                                               │ Live Frame Capture
                                               ▼
                  ┌─────────────────────────────────────────────────────────┐
                  │              YOLOv10 Plate Detection Model              │
                  │              (Bounding Box & Plate Cropping)            │
                  └────────────────────────────┬────────────────────────────┘
                                               │
                                               ▼
                  ┌─────────────────────────────────────────────────────────┐
                  │         Image Preprocessing & Quality Assessment        │
                  │   - CLAHE Contrast Normalization                        │
                  │   - Bicubic Scaling to 70px Height                      │
                  │   - Laplacian Sharpness & Brightness Filter             │
                  └────────────────────────────┬────────────────────────────┘
                                               │
       ┌───────────────────────┬───────────────┴───────────────┬───────────────────────┐
       ▼                       ▼                               ▼                       ▼
┌──────────────┐       ┌──────────────┐                ┌──────────────┐        ┌──────────────┐
│   Reader 1   │       │   Reader 2   │                │   Reader 3   │        │   Reader 4   │
│   EasyOCR    │       │  PaddleOCR   │                │    LPRNet    │        │  GPT Vision  │
│(Spatial/Tok) │       │ (PP-OCRv6)   │                │  (CNN-CTC)   │        │ (Multimodal) │
└──────┬───────┘       └──────┬───────┘                └──────┬───────┘        └──────┬───────┘
       │                      │                               │                       │
       └──────────────────────┼───────────────────────────────┴───────────────────────┘
                              │ Predictions (Text + Confidence)
                              ▼
        ┌─────────────────────────────────────────────────────────────┐
        │            Python Arbitration & Voting Engine               │
        │  1. Registered Vehicle MySQL Priority Lookup                │
        │  2. Majority Voting (4/4, 3/4, 2/4 Plurality, 2-vs-2 Tie)   │
        │  3. Character-Level Positional Disambiguation (Z/2, D/0...) │
        │  4. Philippine LTO Plate Format Template Validation         │
        │  5. Confidence Calibration & Python Accept/Review Decision  │
        └─────────────────────────────┬───────────────────────────────┘
                                      │
                                      ▼
        ┌─────────────────────────────────────────────────────────────┐
        │                 MySQL / MariaDB Vehicle Logging             │
        │   - Registered Plate -> vehicle_logs (Category: Registered) │
        │   - Unknown Plate    -> vehicle_logs (Category: Visitor)    │
        │   - Real-time Web UI -> guard_dashboard.php & Live Polling  │
        └─────────────────────────────────────────────────────────────┘
```

### The 4 Readers Explained

| Reader | Model Type | Primary Strength |
| :--- | :--- | :--- |
| **1. EasyOCR** | CRAFT + ResNet-LSTM-CTC | Spatial character segmentation and reading degraded/skewed plates. |
| **2. PaddleOCR** | PP-OCRv6 DBNet + SVTR | Ultra-fast character tokenization with high precision on straight license plate text. |
| **3. LPRNet** | End-to-end Deep CNN + CTC | Specialized license plate sequence architecture with zero RNN overhead. |
| **4. GPT Vision** | GPT-4o-mini / GPT-4o Multimodal LLM | World knowledge of Philippine plate layout; contextual disambiguation of confusing strokes (**Z vs 2**, **D vs 0**, **B vs 8**, **S vs 5**). |

---

## 2. Installation & Setup

All required packages run on **Python 3.10, 3.11, or 3.12** inside the existing XAMPP/Python workspace.

### Step 2.1: Install OpenAI SDK for GPT Vision
Open PowerShell as Administrator (or in terminal) and install `openai`:

```powershell
pip install openai
```

### Step 2.2: Install Reader Libraries (if not already installed)
To verify or install the full ensemble stack:

```powershell
# PyTorch for YOLOv10 and LPRNet
pip install torch torchvision --index-url https://download.pytorch.org/whl/cu121  # For NVIDIA CUDA
# or for CPU only:
# pip install torch torchvision

# OCR Readers
pip install easyocr
pip install paddleocr paddlepaddle

# Vision & Utility
pip install opencv-python numpy mysql-connector-python flask pillow
```

---

## 3. Configuring GPT Vision as the Fourth Reader

The system comes pre-configured with a dedicated configuration file: [`openai_config.json`](file:///d:/xampp/htdocs/NewCapstone/openai_config.json).

### Step 3.1: Obtain an OpenAI API Key
1. Go to [https://platform.openai.com](https://platform.openai.com) and log in or create an account.
2. Navigate to **API Keys** under your user profile.
3. Click **Create new secret key** (e.g., name it `Capstone_LPR`).
4. Copy your secret key (starts with `sk-proj-...`).

### Step 3.2: Configure the API Key in the System

You have two simple ways to set your key:

#### Method A: Edit `openai_config.json` (Recommended)
Open [`openai_config.json`](file:///d:/xampp/htdocs/NewCapstone/openai_config.json) in your project directory:

```json
{
  "api_key": "sk-proj-YOUR_ACTUAL_API_KEY_HERE",
  "model": "gpt-4o-mini",
  "enabled": true,
  "timeout": 3.0
}
```

> [!TIP]
> `gpt-4o-mini` is recommended as the default model. It is ultra-fast (~0.4s to 0.8s response time), highly accurate for license plate vision, and costs less than \$0.0001 per plate recognition.

#### Method B: Set as Windows Environment Variable
In PowerShell:
```powershell
setx OPENAI_API_KEY "sk-proj-YOUR_ACTUAL_API_KEY_HERE"
```

### Step 3.3: Graceful Standby Mode (Fault Tolerance)
If the API key is not yet set or your internet connection drops:
- The system **will NOT crash**.
- The GPT Vision module automatically reports `STANDBY`.
- The remaining three readers (**EasyOCR**, **PaddleOCR**, and **LPRNet**) immediately carry out 3-model majority voting without interruption.

---

## 4. Code Architecture & Files Created

The complete implementation is structured across the following modular files:

| File | Purpose |
| :--- | :--- |
| [`lpr_engine/gpt_vision_reader.py`](file:///d:/xampp/htdocs/NewCapstone/lpr_engine/gpt_vision_reader.py) | GPT Vision reader class handling base64 image encoding, structured system prompts, and JSON parsing. |
| [`lpr_engine/quad_ensemble.py`](file:///d:/xampp/htdocs/NewCapstone/lpr_engine/quad_ensemble.py) | Quad-Reader Arbitration Engine executing all 4 readers, performing majority voting, 2-vs-2 tie-breaking, character-level positional consensus, and Philippine syntax scoring. |
| [`lpr_engine/triple_ensemble.py`](file:///d:/xampp/htdocs/NewCapstone/lpr_engine/triple_ensemble.py) | Seamless backward-compatibility wrapper pointing directly to `quad_ensemble.py`. |
| [`openai_config.json`](file:///d:/xampp/htdocs/NewCapstone/openai_config.json) | Central JSON configuration for the OpenAI model, API key, timeout, and enabled flag. |
| [`detect_plate_server.py`](file:///d:/xampp/htdocs/NewCapstone/detect_plate_server.py) | Main background Flask server integrating YOLOv10 plate detector with the Quad Ensemble, Tapo C500 RTSP streaming, MySQL logging, and cache invalidation. |

---

## 5. Decision & Voting Mechanism (Detailed Logic)

When a vehicle plate crop is passed to `QuadReaderEnsemble.recognize_ensemble(bgr_crop)`:

### Step 1: Image Quality Gate
- Calculates **Laplacian variance** for sharpness ($S$).
- Calculates mean brightness ($B$) and contrast standard deviation ($C$).
- If $S < 8.0$ (severe motion blur) or width $< 45\text{px}$, returns `decision: "NEEDS_REVIEW"` with reason `QUALITY_GATE_REJECTION`.

### Step 2: Parallel 4-Reader Inference
- **EasyOCR**: Runs on raw and CLAHE-sharpened crop $\rightarrow$ produces `(easy_plate, easy_conf)`.
- **PaddleOCR**: Runs on standardized 70px cubic crop $\rightarrow$ produces `(pad_plate, pad_conf)`.
- **LPRNet**: Runs CNN-CTC forward pass on normalized tensor $\rightarrow$ produces `(lpr_plate, lpr_conf)`.
- **GPT Vision**: Sends base64 image with Philippine disambiguation prompt $\rightarrow$ produces `(gpt_plate, gpt_conf)`.

### Step 3: Priority Check - Registered Vehicle Database
Before voting, every candidate string is checked against `registered_vehicles` in MySQL:
- If any candidate matches an active resident plate $\rightarrow$ **Instant Accept** (`method: "REGISTERED_MATCH"`).

### Step 4: String-Level Majority Voting
1. **Unanimous (4 of 4 agree)**:
   - Example: All 4 models predict `"NDI2459"`.
   - **Result:** Accepted with $\ge 98\%$ confidence (`MAJORITY_4_OF_4`).
2. **Clear Majority (3 of 4 agree)**:
   - Example: EasyOCR, PaddleOCR, and GPT Vision say `"D566CP"`, LPRNet says `"0566CP"`.
   - **Result:** Majority candidate `"D566CP"` wins (`MAJORITY_3_OF_4`).
3. **Plurality (2 of 4 with no tie)**:
   - Example: PaddleOCR and GPT Vision say `"NDI2459"`, EasyOCR says `"ND12459"`, LPRNet says `"MDI2459"`.
   - **Result:** Candidate supported by 2 models wins (`MAJORITY_2_OF_4`).
4. **Tie (2 vs 2)**:
   - Example: Models A & B predict `"D566CP"`, Models C & D predict `"0566CP"`.
   - **Arbitration:** Evaluates positional syntax. Position 0 in a 6-character Philippine conduction plate requires a letter $\rightarrow$ `'D'` wins over `'0'`. If GPT Vision is in one pair, its semantic weighting resolves the tie (`TIE_RESOLVED_2_VS_2`).

### Step 5: Character-Level Positional Consensus
If all 4 models disagree on the full string (e.g. `1 vs 1 vs 1 vs 1`), the system executes **positional alignment**:
- Position 0: Models vote $\rightarrow$ syntax enforces `LETTER`.
- Position 1: Models vote $\rightarrow$ syntax enforces `DIGIT`.
- Position 2: Models vote $\rightarrow$ syntax enforces `DIGIT`.
- Position 3: Models vote $\rightarrow$ syntax enforces `DIGIT`.
- Position 4: Models vote $\rightarrow$ syntax enforces `LETTER`.
- Position 5: Models vote $\rightarrow$ syntax enforces `LETTER`.

**Confusable Disambiguation Table:**
| Position Expectation | Ambiguous Char Seen | Converted To |
| :--- | :--- | :--- |
| **LETTER** | `2` | **`Z`** |
| **LETTER** | `0` | **`D`** |
| **LETTER** | `8` | **`B`** |
| **LETTER** | `5` | **`S`** |
| **LETTER** | `1` | **`I`** |
| **DIGIT** | `Z` | **`2`** |
| **DIGIT** | `D`, `O`, `Q` | **`0`** |
| **DIGIT** | `B`, `R` | **`8`** |
| **DIGIT** | `S` | **`5`** |
| **DIGIT** | `I`, `L`, `T` | **`1`** |

The resulting reconstructed plate is validated against Philippine LTO templates (`CHARACTER_CONSENSUS`).

---

## 6. How to Test and Verify

> [!IMPORTANT]
> **Important Directory Requirement:** Always navigate to your project directory first in PowerShell before running test commands, otherwise Python cannot locate the `lpr_engine` package:
> ```powershell
> cd D:\xampp\htdocs\NewCapstone
> ```

### Step 6.1: Test GPT Vision Standalone
You can run the dedicated test script directly:

```powershell
python test_gpt_vision.py
```

Or run directly via command line:
```powershell
cd D:\xampp\htdocs\NewCapstone
python -c "import sys; sys.path.insert(0, '.'); from lpr_engine.gpt_vision_reader import GPTVisionReader; r = GPTVisionReader(); print('Configured:', r.is_configured(), '| Model:', r.model)"
```

*Expected output:*
```
============================================================
  TESTING GPT VISION READER (FOURTH READER)
============================================================
[GPTVision] Ready! Model: gpt-4o-mini (Timeout: 3.0s)

Model:      gpt-4o-mini
Timeout:    3.0s
Configured: True

[SUCCESS] GPT Vision is READY and properly configured with your API key!
```

### Step 6.2: Test the Entire Quad Ensemble on an Image
Run the dedicated Quad-Reader test script:

```powershell
python test_quad_ensemble.py
```

*Expected output:*
```
============================================================
  TESTING 4-READER OCR ENSEMBLE PIPELINE
============================================================

Ensemble Reader Status:
- EasyOCR:    Configured
- PaddleOCR:  Configured
- LPRNet:     Configured
- GPTVision:  READY

Running test inference on sample plate crop...
Decision:     ACCEPT
Method:       MAJORITY_4_OF_4
[SUCCESS] Quad-Reader Ensemble pipeline is working correctly!
```

### Step 6.3: Check Live Server Status
Verify that `detect_plate_server.py` is actively streaming and reports the Quad Ensemble:

```powershell
cd D:\xampp\htdocs\NewCapstone
python -c "import urllib.request, json; resp = urllib.request.urlopen('http://127.0.0.1:5000/api/status'); print(json.dumps(json.loads(resp.read().decode())['quad_ensemble'], indent=2))"
```

*Output:*
```json
{
  "easyocr": true,
  "enabled": true,
  "gpt_vision": true,
  "lprnet": true,
  "paddleocr": true
}
```

---

## 7. Troubleshooting & Frequently Asked Questions

### Q1: What happens if the OpenAI API key runs out of credits?
> **Answer:** The `GPTVisionReader` catches the OpenAI quota exception, logs a single warning, and returns an empty reading. The system **automatically and gracefully continues processing** using the other three local offline readers (**EasyOCR**, **PaddleOCR**, and **LPRNet**). Camera streaming and guard dashboard logging never freeze.

### Q2: Why is `gpt-4o-mini` preferred over `gpt-4o`?
> **Answer:** For cropped license plate images:
> 1. `gpt-4o-mini` latency is typically **300ms to 600ms**, ideal for live gate logging.
> 2. `gpt-4o-mini` is over **95% cheaper** than standard `gpt-4o` while maintaining equivalent recognition precision on cropped characters.
> 3. If you want to switch to full `gpt-4o`, simply change `"model": "gpt-4o"` in [`openai_config.json`](file:///d:/xampp/htdocs/NewCapstone/openai_config.json).

### Q3: How do I change the timeout for GPT Vision?
> In [`openai_config.json`](file:///d:/xampp/htdocs/NewCapstone/openai_config.json), adjust `"timeout": 2.5` (seconds). If OpenAI takes longer than this value, the server drops the request and relies on the local models so the vehicle logging is never delayed.


"""
=============================================================================
Google Colab Training Script: License Plate Detection with YOLOv10
=============================================================================
Author: Machine Learning & Computer Vision Engineer
Project: License Plate Detection (Capstone Project)
Target Architecture: YOLOv10-Small (yolov10s.pt)

INSTRUCTIONS FOR GOOGLE COLAB:
1. Open Google Colab (https://colab.research.google.com).
2. Set Runtime to GPU:
   Runtime -> Change runtime type -> Hardware accelerator -> GPU (T4 or better).
3. You can copy-paste each section (demarcated by '# %%') into its own Colab cell,
   or upload this file directly to Colab and run through cells interactively.
=============================================================================
"""

# %% [Cell 1] Step 1: Check GPU Availability and System Resources
# -----------------------------------------------------------------------------
# Google Colab provides free GPU runtimes (usually NVIDIA T4, V100, or A100).
# This cell verifies that a GPU is allocated and checks PyTorch CUDA support.
# -----------------------------------------------------------------------------
import os
import sys
import torch

print("=" * 60)
print("Step 1: Checking GPU and CUDA Availability")
print("=" * 60)

# Check PyTorch CUDA availability
cuda_available = torch.cuda.is_available()
print(f"CUDA Available: {cuda_available}")

if cuda_available:
    device_count = torch.cuda.device_count()
    device_name = torch.cuda.get_device_name(0)
    print(f"GPU Device Count: {device_count}")
    print(f"Active GPU: {device_name}")
    print(f"PyTorch Version: {torch.__version__}")
    print(f"CUDA Version: {torch.version.cuda}")
else:
    print("\n[WARNING] No GPU detected!")
    print("Please go to 'Runtime' -> 'Change runtime type' -> Select 'T4 GPU' and re-run.")

# Run nvidia-smi command to print GPU driver and VRAM stats
# In Colab cells, you can also run: !nvidia-smi
print("\n--- NVIDIA System Management Interface (nvidia-smi) ---")
os.system("nvidia-smi")


# %% [Cell 2] Step 2 & 3: Clone YOLOv10 Repository and Install Dependencies
# -----------------------------------------------------------------------------
# YOLOv10 is developed by Tsinghua University (THU-MIG).
# We clone the official repository and install it in editable mode.
# -----------------------------------------------------------------------------
print("=" * 60)
print("Step 2 & 3: Cloning YOLOv10 Repo and Installing Dependencies")
print("=" * 60)

# Define root workspace in Colab
WORKSPACE_DIR = "/content"
os.chdir(WORKSPACE_DIR)

# Clone the official YOLOv10 repository if not already cloned
if not os.path.exists(os.path.join(WORKSPACE_DIR, "yolov10")):
    print("Cloning THU-MIG YOLOv10 repository...")
    os.system("git clone https://github.com/THU-MIG/yolov10.git")
else:
    print("YOLOv10 repository already exists. Skipping clone.")

# Navigate into the cloned repository and install the package
YOLOV10_DIR = os.path.join(WORKSPACE_DIR, "yolov10")
os.chdir(YOLOV10_DIR)

print("\nInstalling YOLOv10 package and dependencies...")
# In Colab notebook cell:
# %cd /content/yolov10
# !pip install .
os.system("pip install -q .")

# Install additional utility dependencies for visualization and export
os.system("pip install -q onnx onnxscript onnxruntime matplotlib opencv-python pyyaml")

print("YOLOv10 and dependencies installed successfully!")


# %% [Cell 4] Step 4: Download Pretrained YOLOv10 Weights
# -----------------------------------------------------------------------------
# Download pretrained COCO weights released by the YOLOv10 authors.
# Models available:
#   - yolov10n (Nano - fastest, lowest compute)
#   - yolov10s (Small - excellent balance of speed and accuracy, recommended)
#   - yolov10m (Medium)
#   - yolov10b (Balanced)
#   - yolov10x (Extra-large - highest accuracy, heavier compute)
# -----------------------------------------------------------------------------
import urllib.request
from pathlib import Path

print("=" * 60)
print("Step 4: Downloading Pretrained YOLOv10 Weights")
print("=" * 60)

WEIGHTS_DIR = Path("/content/yolov10/weights")
WEIGHTS_DIR.mkdir(parents=True, exist_ok=True)

WEIGHT_FILES = [
    "yolov10n.pt",
    "yolov10s.pt",
    "yolov10m.pt",
    "yolov10b.pt",
    "yolov10x.pt",
]

BASE_DOWNLOAD_URL = "https://github.com/jameslahm/yolov10/releases/download/v1.0/"

for weight_name in WEIGHT_FILES:
    destination_path = WEIGHTS_DIR / weight_name
    if not destination_path.exists():
        download_url = BASE_DOWNLOAD_URL + weight_name
        print(f"Downloading {weight_name} from {download_url}...")
        try:
            urllib.request.urlretrieve(download_url, destination_path)
            print(f"Successfully downloaded: {destination_path}")
        except Exception as e:
            print(f"Error downloading {weight_name}: {e}")
    else:
        print(f"Weight file already present: {destination_path}")

print(f"\nAll weights stored in: {WEIGHTS_DIR}")


# %% [Cell 5] Step 5: Mount Google Drive
# -----------------------------------------------------------------------------
# Mount Google Drive to load your dataset or save final model checkpoints permanently.
# Files in /content/ are temporary and deleted when Colab session ends.
# -----------------------------------------------------------------------------
print("=" * 60)
print("Step 5: Mounting Google Drive")
print("=" * 60)

try:
    from google.colab import drive
    drive.mount("/content/drive")
    print("Google Drive mounted successfully at /content/drive")
except ImportError:
    print("[NOTE] Running outside Google Colab environment. Skipping drive.mount.")
except Exception as e:
    print(f"Notice during drive mount: {e}")


# %% [Cell 6] Step 6: Upload and Unzip the Number Plate Dataset
# -----------------------------------------------------------------------------
# Dataset Expected Structure:
#   /content/number_plate_dataset/
#   ├── train/
#   │   ├── images/  (*.jpg, *.png)
#   │   └── labels/  (*.txt YOLO format: class_id center_x center_y width height)
#   ├── valid/
#   │   ├── images/
#   │   └── labels/
#   ├── test/
#   │   ├── images/
#   │   └── labels/
#   └── data.yaml
#
# You can unzip your dataset from Google Drive or from uploaded zip files.
# -----------------------------------------------------------------------------
import glob
import os
import shutil
import zipfile
from pathlib import Path

print("=" * 60)
print("Step 6: Extracting License Plate Dataset")
print("=" * 60)

DATASET_TARGET_DIR = Path("/content/number_plate_dataset")
DATASET_TARGET_DIR.mkdir(parents=True, exist_ok=True)

# 1. Check if dataset is ALREADY extracted and ready
train_imgs = list((DATASET_TARGET_DIR / "train" / "images").glob("*.*")) if (DATASET_TARGET_DIR / "train" / "images").exists() else []
if len(train_imgs) > 50:
    print(f"[OK] Dataset is already extracted at {DATASET_TARGET_DIR} ({len(train_imgs)} train images found). Skipping unzip.")
else:
    # 2. Search for any zip file in /content, Google Drive, or current directory
    search_patterns = [
        "/content/*.zip",
        "/content/drive/MyDrive/*.zip",
        "/content/drive/MyDrive/*/*.zip",
        "/content/yolov10/*.zip",
        "*.zip"
    ]

    found_zips = []
    for pattern in search_patterns:
        found_zips.extend(glob.glob(pattern))

    # Priority to zip files matching 'plate', 'license', 'number', 'dataset'
    matching_zips = [z for z in found_zips if any(k in os.path.basename(z).lower() for k in ["plate", "license", "number", "data"])]
    target_zip = matching_zips[0] if matching_zips else (found_zips[0] if found_zips else None)

    # 3. If no zip found, provide interactive Colab file upload
    if not target_zip:
        print("[NOTICE] No dataset zip file found automatically in /content or Google Drive.")
        print("Please choose and upload your 'number_plate.zip' from your computer below:")
        try:
            from google.colab import files
            uploaded = files.upload()
            if uploaded:
                target_zip = list(uploaded.keys())[0]
        except Exception as e:
            print(f"Interactive upload error: {e}")

    # 4. Extract the zip file
    if target_zip and os.path.exists(target_zip):
        print(f"Extracting dataset archive from: {target_zip}")
        print("Extracting files to /content/number_plate_dataset ...")
        with zipfile.ZipFile(target_zip, "r") as archive:
            archive.extractall(DATASET_TARGET_DIR)
        print("Extraction complete!")
    else:
        print("[WARNING] Could not find or upload a zip file.")
        print("If you haven't uploaded 'number_plate.zip', upload it to Google Drive or Colab.")

# 5. Handle nested folder if zip contained a single parent root folder
nested_dirs = [d for d in DATASET_TARGET_DIR.iterdir() if d.is_dir() and (d / "train").exists()]
if nested_dirs:
    nested_root = nested_dirs[0]
    print(f"Unflattening nested folder: {nested_root} -> {DATASET_TARGET_DIR}")
    for item in nested_root.iterdir():
        dest = DATASET_TARGET_DIR / item.name
        if not dest.exists():
            shutil.move(str(item), str(dest))

# 6. Verify and show extracted image counts
print("\n--- Extracted Dataset Summary ---")
for split in ["train", "valid", "test"]:
    img_dir = DATASET_TARGET_DIR / split / "images"
    lbl_dir = DATASET_TARGET_DIR / split / "labels"
    img_count = len(list(img_dir.glob("*.*"))) if img_dir.exists() else 0
    lbl_count = len(list(lbl_dir.glob("*.txt"))) if lbl_dir.exists() else 0
    print(f"  Split [{split:5s}]: {img_count:4d} images | {lbl_count:4d} labels")


# %% [Cell 7] Step 7: Create Corrected data.yaml with Colab Paths
# -----------------------------------------------------------------------------
# data.yaml tells YOLOv10 where to find train, val, and test splits,
# along with class count (nc) and class names.
# -----------------------------------------------------------------------------
import yaml

print("=" * 60)
print("Step 7: Creating Corrected data.yaml")
print("=" * 60)

DATA_YAML_PATH = "/content/number_plate_dataset/data.yaml"

dataset_config = {
    "train": "/content/number_plate_dataset/train/images",
    "val": "/content/number_plate_dataset/valid/images",
    "test": "/content/number_plate_dataset/test/images",
    "nc": 1,
    "names": ["license"]
}

# Write YAML configuration
with open(DATA_YAML_PATH, "w") as f:
    yaml.dump(dataset_config, f, sort_keys=False, default_flow_style=None)

print(f"Config successfully written to: {DATA_YAML_PATH}")
print("Content of data.yaml:")
with open(DATA_YAML_PATH, "r") as f:
    print(f.read())


# %% [Cell 8] Step 8: Train YOLOv10 on License Plate Dataset
# -----------------------------------------------------------------------------
# Hyperparameters:
#   - Model: yolov10s.pt (Small variant, optimal tradeoff of mAP and FPS)
#   - Image size (imgsz): 640 (standard YOLO resolution for license plates)
#   - Batch size: 16 (fits comfortably on Colab 16GB T4 VRAM)
#   - Epochs: 50 (sufficient for transfer learning convergence)
#   - Device: 0 (Colab GPU)
#   - Optimizer: auto (AdamW / SGD selected automatically)
# -----------------------------------------------------------------------------
import os
import torch
import torch.serialization

# -----------------------------------------------------------------------------
# 1. Disable wandb (Weights & Biases) to prevent project name error & login prompts
# -----------------------------------------------------------------------------
os.environ["WANDB_DISABLED"] = "true"
os.environ["WANDB_MODE"] = "disabled"

# -----------------------------------------------------------------------------
# 2. PyTorch 2.6+ Compatibility Fix (Recursion-safe):
# Route torch.load to torch.serialization.load with weights_only=False
# -----------------------------------------------------------------------------
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

try:
    from ultralytics import settings
    settings.update({"wandb": False})
except Exception:
    pass

from ultralytics import YOLOv10

print("=" * 60)
print("Step 8: Starting YOLOv10 Training")
print("=" * 60)

# Path to initial pretrained weights
PRETRAINED_MODEL = "/content/yolov10/weights/yolov10s.pt"

# Initialize YOLOv10 model with pretrained weights
model = YOLOv10(PRETRAINED_MODEL)

# Train using Python API
results = model.train(
    data="/content/number_plate_dataset/data.yaml",
    epochs=50,
    batch=16,
    imgsz=640,
    device=0,            # Use 0 for GPU, 'cpu' if no GPU available
    workers=4,           # Dataloader workers
    project="license_plate_detector",  # Clean name without '/' to prevent wandb UsageError
    name="yolov10s",
    exist_ok=True,       # Overwrite existing run directory if re-running
    pretrained=True,     # Start from pretrained weights
    save=True,           # Save checkpoints
    save_period=10,      # Checkpoint every 10 epochs
    plots=True           # Save training plots and confusion matrices
)

print("\nTraining completed successfully!")
print("Artifacts saved under: license_plate_detector/yolov10s")

# Alternative CLI execution (can be run directly in Colab cell):
# !yolo task=detect mode=train \
#   model=/content/yolov10/weights/yolov10s.pt \
#   data=/content/number_plate_dataset/data.yaml \
#   epochs=50 imgsz=640 batch=16 device=0 \
#   project=license_plate_detector name=yolov10s exist_ok=True


# %% [Cell 9] Step 9: Validate the Trained Model
# -----------------------------------------------------------------------------
# Evaluate performance on the validation set using mAP@0.5 and mAP@0.5:0.95.
# -----------------------------------------------------------------------------
from pathlib import Path

print("=" * 60)
print("Step 9: Evaluating Model on Validation Set")
print("=" * 60)

# Search candidate locations for the best trained weights
candidate_weights = [
    Path(getattr(getattr(model, "trainer", None), "best", "")) if hasattr(model, "trainer") else None,
    Path("/content/yolov10/license_plate_detector/yolov10s/weights/best.pt"),
    Path("/content/license_plate_detector/yolov10s/weights/best.pt"),
    Path("license_plate_detector/yolov10s/weights/best.pt"),
    Path("/content/yolov10/runs/detect/license_plate_yolov10s/weights/best.pt"),
    Path("/content/runs/detect/license_plate_yolov10s/weights/best.pt"),
]

BEST_WEIGHTS = None
for p in candidate_weights:
    if p and Path(p).exists():
        BEST_WEIGHTS = str(p)
        break

if not BEST_WEIGHTS:
    BEST_WEIGHTS = "/content/license_plate_detector/yolov10s/weights/best.pt"

print(f"Loading best weights from: {BEST_WEIGHTS}")
# Load the best fine-tuned model checkpoint
best_model = YOLOv10(BEST_WEIGHTS)

# Run validation
val_metrics = best_model.val(
    data="/content/number_plate_dataset/data.yaml",
    split="val",
    imgsz=640,
    batch=16,
    device=0
)

print("\n--- Validation Metrics ---")
print(f"mAP@50     : {val_metrics.box.map50:.4f}")
print(f"mAP@50-95  : {val_metrics.box.map:.4f}")
print(f"Precision  : {val_metrics.box.mp:.4f}")
print(f"Recall     : {val_metrics.box.mr:.4f}")


# %% [Cell 10] Step 10: Run Inference on Test Images & Visualize
# -----------------------------------------------------------------------------
# Run prediction on test split images and visualize detections.
# -----------------------------------------------------------------------------
import cv2
import glob
import matplotlib.pyplot as plt

print("=" * 60)
print("Step 10: Running Inference on Test Images")
print("=" * 60)

TEST_IMAGES_DIR = "/content/number_plate_dataset/test/images"
test_image_paths = sorted(glob.glob(f"{TEST_IMAGES_DIR}/*.*"))[:6]

if len(test_image_paths) == 0:
    print("No test images found in test/images. Checking valid/images...")
    test_image_paths = sorted(glob.glob("/content/number_plate_dataset/valid/images/*.*"))[:6]

print(f"Running inference on {len(test_image_paths)} sample images...")

# Predict using best model
predictions = best_model.predict(
    source=test_image_paths,
    conf=0.25,          # Confidence threshold
    iou=0.45,           # NMS IoU threshold
    device=0,
    save=True,          # Save annotated images
    project="license_plate_detector",
    name="test_predictions",
    exist_ok=True
)

# Plot predictions in a grid
if len(predictions) > 0:
    num_imgs = len(predictions)
    fig, axes = plt.subplots(nrows=(num_imgs + 2) // 3, ncols=min(3, num_imgs), figsize=(15, 10))
    axes = axes.flatten() if hasattr(axes, "flatten") else [axes]

    for idx, pred in enumerate(predictions):
        # pred.plot() returns BGR numpy array with bounding boxes & labels
        plotted_bgr = pred.plot()
        plotted_rgb = cv2.cvtColor(plotted_bgr, cv2.COLOR_BGR2RGB)

        axes[idx].imshow(plotted_rgb)
        axes[idx].set_title(f"Test #{idx+1} (Boxes: {len(pred.boxes)})")
        axes[idx].axis("off")

    # Hide extra unused subplot axes
    for idx in range(len(predictions), len(axes)):
        axes[idx].axis("off")

    plt.tight_layout()
    plt.show()


# %% [Cell 11] Step 11: Export the Best Model (ONNX format)
# -----------------------------------------------------------------------------
# Export the trained model to ONNX for fast edge inference (e.g. OpenCV DNN,
# TensorRT, OpenVINO, or deployment in desktop/web apps).
# -----------------------------------------------------------------------------
import os

print("=" * 60)
print("Step 11: Exporting Best Model to ONNX")
print("=" * 60)

try:
    import onnxscript
except ImportError:
    print("Installing onnxscript dependency...")
    os.system("pip install -q onnxscript")

try:
    exported_path = best_model.export(
        format="onnx",
        imgsz=640,
        dynamic=False,   # Static shapes for high-performance deployment
        opset=12
    )
    print(f"Model successfully exported to ONNX: {exported_path}")
except Exception as e:
    print(f"Export notice/error: {e}")


# %% [Cell 12] Step 12: Copy Trained Weights to Google Drive for Permanent Storage
# -----------------------------------------------------------------------------
# Back up best.pt and exported ONNX model to your Google Drive to ensure weights
# are preserved after the Colab session terminates.
# -----------------------------------------------------------------------------
import shutil
from pathlib import Path

print("=" * 60)
print("Step 12: Backing up Trained Weights to Google Drive")
print("=" * 60)

DRIVE_BACKUP_DIR = Path("/content/drive/MyDrive/YOLOv10_License_Plate_Checkpoint")
DRIVE_BACKUP_DIR.mkdir(parents=True, exist_ok=True)

SOURCE_WEIGHTS = Path(BEST_WEIGHTS)
LAST_WEIGHTS = SOURCE_WEIGHTS.parent / "last.pt"
ONNX_WEIGHTS = SOURCE_WEIGHTS.parent / "best.onnx"

# Files to back up
files_to_copy = [SOURCE_WEIGHTS, LAST_WEIGHTS, ONNX_WEIGHTS]

for file_path in files_to_copy:
    if file_path.exists():
        dest = DRIVE_BACKUP_DIR / file_path.name
        shutil.copy2(file_path, dest)
        print(f"Copied {file_path.name} -> {dest}")
    else:
        print(f"File not found (skipped): {file_path}")

print("\n" + "=" * 60)
print("ALL STEPS COMPLETED!")
print(f"Your best weights are stored safely in: {DRIVE_BACKUP_DIR}")
print("You can download 'best.pt' and integrate it with your Capstone application.")
print("=" * 60)

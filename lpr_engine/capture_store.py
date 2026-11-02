"""Persist a confirmed plate image alongside its recognition metadata."""
import json
from datetime import datetime
from pathlib import Path
import uuid

import cv2


def save_plate_capture(directory, crop, metadata):
    if crop is None or crop.size == 0:
        raise ValueError("Cannot save an empty plate crop")
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    name = datetime.now().strftime("%Y%m%d_%H%M%S_%f") + "_" + uuid.uuid4().hex[:8]
    image_name, metadata_name = name + ".jpg", name + ".json"
    success, encoded = cv2.imencode(".jpg", crop, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not success:
        raise OSError("Plate JPEG encoding failed")
    image_path = directory / image_name
    metadata_path = directory / metadata_name
    try:
        image_path.write_bytes(encoded.tobytes())
        metadata_path.write_text(json.dumps(dict(metadata, image=image_name), indent=2), encoding="utf-8")
    except Exception:
        image_path.unlink(missing_ok=True)
        metadata_path.unlink(missing_ok=True)
        raise
    return {"image": image_name, "metadata": metadata_name}

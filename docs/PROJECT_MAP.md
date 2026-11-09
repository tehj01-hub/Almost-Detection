# Project file and debugging guide

Paths in commands below are relative to the project root (`D:\xampp\htdocs\NewCapstone`).

## Folder layout

```text
NewCapstone/
|-- app/                     Shared PHP session helpers and system rules
|-- assets/js/               Browser refresh and visitor alarm scripts
|-- database/                SQL updates
|-- docs/
|   |-- PROJECT_MAP.md       Start here when locating a bug
|   |-- setup/               Camera/YOLO connection and Colab notes
|   `-- superpowers/plans/   Organization implementation record
|-- lpr_engine/              OCR readers, preprocessing, decoding, validation
|-- tests/                   Standalone detection and OCR checks
|   `-- run_all.py           Runs every automated unit test
|-- tools/                   Crop collection/labeling and OCR evaluation
|-- training/                Training scripts, notebooks, and dataset YAML
|-- *.php                   Public pages and the visitor alarm API
|-- detect_plate_server.py   Detection server (camera + YOLOv10 + OCR + MySQL)
`-- requirements.txt         Python dependencies
```

The following existing local folders stay in place: `weights/` (trained models),
`numberplate_dataset/` (training images/labels), `uploads/` (application
uploads; vehicle photos in `uploads/vehicle_captures/` are served only through
`vehicle_photo.php`), `vendor/` (PHP libraries, e.g. Dompdf), `yolov10/` (local
detection library), and the model cache folders. The server runs on the
normal Python install (`python -u detect_plate_server.py`).
Their contents are ignored by Git. Local `camera_config.json` and
`openai_config.json` also stay at the root and are ignored.

## Find a bug by feature

| Symptom or feature | Start here | Related files |
| --- | --- | --- |
| Login, logout, role separation, session cookies | [app/session_helper.php](../app/session_helper.php) | `login.php`, `logout.php`, `index.php` |
| Administrator dashboard | [superadmin_dashboard.php](../superadmin_dashboard.php) | `app/system_config.php`, `assets/js/auto_refresh.js` |
| Guard dashboard or camera settings form | [guard_dashboard.php](../guard_dashboard.php) | `guard_select_partner.php`, root `camera_config.json`, detection server |
| Visitor registration, archive, or log display | [visitor.php](../visitor.php) | `visitor_logs.php`, `app/system_config.php` |
| Registered vehicles and their logs | [registered.php](../registered.php) | `registered_logs.php` |
| Visit duration, purpose, timer state, or schema upgrades | [app/system_config.php](../app/system_config.php) | `visitor_alarm_api.php`, `database/database_updates.sql` |
| Alarm sound, countdown, reminders, or snooze | [assets/js/visitor_duration_alarm.js](../assets/js/visitor_duration_alarm.js) | `visitor_alarm_api.php`, `app/system_config.php` |
| Dashboard data does not refresh | [assets/js/auto_refresh.js](../assets/js/auto_refresh.js) | Relevant PHP page and its `data-live` markup |
| General logs or PDF/report output | [logs.php](../logs.php) | `report.php`, `composer.json`, `vendor/` |
| Guard accounts, profiles, or security information | [security_guard.php](../security_guard.php) | `profile.php`, `security_information.php` |
| Camera connection or video stream | [detect_plate_server.py](../detect_plate_server.py) | root `camera_config.json`, `guard_dashboard.php` (camera Setup), `camera_proxy.php` |
| Plate is not detected or model will not load | [detect_plate_server.py](../detect_plate_server.py) | local `weights/`, local `yolov10/`, `tests/test_plate_detection.py` |
| Plate is found but characters are wrong | [lpr_engine/quad_ensemble.py](../lpr_engine/quad_ensemble.py) | `recognizer.py`, `preprocessing.py`, `ctc_decoder.py`, `ph_plate_validator.py` in `lpr_engine/` |
| GPT reader configuration | [lpr_engine/gpt_vision_reader.py](../lpr_engine/gpt_vision_reader.py) | root `openai_config.json`, `tests/test_gpt_vision.py` |
| OpenVINO acceleration or benchmark behavior | [detect_plate_openvino_test.py](../detect_plate_openvino_test.py) | `detect_plate_server_openvino.py`, local `weights/best_openvino_model/` |
| Training, labels, or recognition accuracy comparisons | [training/train_lprnet_ph.py](../training/train_lprnet_ph.py) | `tools/collect_and_label_crops.py`, `tools/evaluate_ocr_models.py`, `training/data.yaml` |

The three detection server files are preserved variants. The file you actually
launch determines which implementation is active. `detect_plate_openvino_test.py`
is a full standalone server with a self-test option, not a unit test; it stays at
the root with the other launch files.

Database connections and some camera settings are currently repeated in several
PHP/Python files. If one page or server cannot connect, inspect that specific
file's configuration; moving the shared helpers does not centralize those settings.

## Run and check the project

Run server commands from the project root. Start the intended server variant,
rather than launching all three together on the same port:

```powershell
python detect_plate_server.py
# Alternative server launches:
python detect_plate_server_openvino.py
python detect_plate_openvino_test.py
```

Use XAMPP's Apache for the PHP pages and MySQL for the application database.
Existing page URLs such as `/NewCapstone/login.php` and
`/NewCapstone/guard_dashboard.php` keep the same paths.

```powershell
# All automated unit tests in one run (sets up the import paths they need)
python tests/run_all.py

# Offline OCR engine checks: synthetic inputs, no camera or API call
python tests/test_lprnet_pipeline.py

# Check the GPT reader configuration
python tests/test_gpt_vision.py

# Standalone ensemble check (depends on available reader configuration)
python tests/test_quad_ensemble.py

# Detection and OCR on an image; requires model/OCR dependencies
python tests/test_plate_detection.py "path/to/vehicle.jpg"

# Full OpenVINO self-test; requires the model and hardware runtime
python detect_plate_openvino_test.py --self-test

# Training and evaluation command options
python training/train_lprnet_ph.py --help
python tools/evaluate_ocr_models.py --help
python tools/collect_and_label_crops.py --help
```

The image detection check writes `tests/artifacts/test_result.jpg`.
Training still reads the root datasets and writes weights under root `weights/`.
The YOLO training notebooks and `training/train_license_plate.py` are Colab
material; the latter installs packages and trains a model when executed. Do not
run it as a quick syntax or health check. `training/data.yaml` retains its
existing Colab paths; edit the dataset paths before using it for local training.

See [camera and YOLO setup](setup/HOW_TO_CONNECT_YOLOV10.md) and
[the preserved Colab notes](setup/colab.md) for existing setup instructions.

## Files moved during organization

| Previous path | Current path |
| --- | --- |
| `session_helper.php` | `app/session_helper.php` |
| `system_config.php` | `app/system_config.php` |
| `auto_refresh.js` | `assets/js/auto_refresh.js` |
| `visitor_duration_alarm.js` | `assets/js/visitor_duration_alarm.js` |
| `test_gpt_vision.py` | `tests/test_gpt_vision.py` |
| `test_lprnet_pipeline.py` | `tests/test_lprnet_pipeline.py` |
| `test_plate_detection.py` | `tests/test_plate_detection.py` |
| `test_quad_ensemble.py` | `tests/test_quad_ensemble.py` |
| `test_result.jpg` | `tests/artifacts/test_result.jpg` |
| `train_license_plate.py` | `training/train_license_plate.py` |
| `train_lprnet_ph.py` | `training/train_lprnet_ph.py` |
| `train_license_plate.ipynb` | `training/train_license_plate.ipynb` |
| `train_yolov10.ipynb` | `training/train_yolov10.ipynb` |
| `data.yaml` | `training/data.yaml` |
| `evaluate_ocr_models.py` | `tools/evaluate_ocr_models.py` |
| `database_updates.sql` | `database/database_updates.sql` |
| `HOW_TO_CONNECT_YOLOV10.md` | `docs/setup/HOW_TO_CONNECT_YOLOV10.md` |
| `colab.md` | `docs/setup/colab.md` |

The old placeholder `docs/archive/git`, the standalone `cctv.html` /
`camera.py` / `camera_stream.py` preview tools, the unused `.venv`, and the
`scratch/` experiments were removed on 2026-11-09 (sent to the Recycle Bin).
Live camera views are on the guard and admin dashboards.

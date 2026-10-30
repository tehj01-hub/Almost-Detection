# Conversation memory

Saved on 2026-10-28 at the user's request. This is a summary, not a verbatim transcript or account-level memory. Future assistants should read this file when continuing work on this project and verify Git state before acting.

## Project and preferences

- Workspace: `D:\xampp\htdocs\NewCapstone` (Windows, XAMPP).
- Repository: https://github.com/tehj01-hub/Almost-Detection.git
- Main branch: `main`, tracking `origin/main`.
- The project uses PHP pages and APIs plus Python plate detection/OCR services. A framework migration is not required merely to run it.
- The user prefers direct action, clear explanations, and few unnecessary confirmation rounds.
- For the organization task, the user explicitly said not to delete anything. Preserve existing files and behavior.

## Earlier work

- Installed 15 Superpowers skills from https://github.com/tehj01-hub/superpowers.git into `C:\Users\Administrator\.codex\skills`.
- The user requested removal of the downloaded repository copy while keeping installed skills. Automated removal was blocked at the time; the workspace copy was later absent.
- Configured `.gitignore` for credentials/config, dependencies, datasets, model weights, generated outputs, caches, environments, and local tooling.
- Removed ignored files from Git tracking while retaining local copies.
- Pushed the cleaned project snapshot to remote `main`, commit `88b6f4f178f55f35c984644e73073a1c7845a0ca` (`Update visitor alarms and exclude local artifacts`).
- Old local history is retained on `master` at `e40c36f`. Do not force-push or publish that old branch without an explicit request.
- Previously tracked credentials/config may still exist in earlier remote history. Ignoring a file does not erase old commits.

## Architecture discussion

- YOLOv10 locates license plates; OCR reads plate text; the PHP application handles entry/exit and visitor records.
- Alternatives include another detector, a fixed camera crop with OCR, or conventional image processing. No replacement was implemented.
- The local ignored `yolov10` folder is still imported by Python and may be required at runtime.
- Syntax checks do not establish that cameras, database connections, credentials, services, and models all work together. Full live flows remain untested.

## File organization completed locally

The user approved organizing files without deleting anything. Runtime PHP routes and Python service launch scripts remain at the project root. Supporting files were moved into:

- `app/`: session and system configuration helpers.
- `assets/js/`: refresh and visitor alarm JavaScript.
- `tests/`: four offline/manual test scripts; generated image under ignored `tests/artifacts/`.
- `training/`: training scripts, notebooks, and dataset YAML.
- `tools/`: OCR evaluation and existing crop collection tooling.
- `database/`: SQL updates.
- `docs/setup/`: setup and Colab instructions.
- `docs/archive/`: existing blank `git` placeholder, preserved.

Nineteen files were moved, including one ignored test image. Includes, script URLs, Python import roots, model/data paths, and documentation links were updated. The ignored local `scratch/test_save_visitor.php` helper includes were also updated.

See [PROJECT_MAP.md](PROJECT_MAP.md) for the full directory guide, old/new paths, commands, and bug-to-file lookup. See [the organization plan](superpowers/plans/2026-10-28-project-organization.md) for implementation and verification details.

## Verification and current handoff

- All 57 inventoried existing files remained present; key service, OCR engine, SQL, YAML, notebook, and image contents were preserved.
- All 18 application PHP files passed syntax checks; 23 Python files parsed; both moved JavaScript files passed syntax checks.
- Offline LPRNet checks passed before and after the moves.
- PHP include and asset paths resolved; both JavaScript assets were served successfully by a temporary local PHP server.
- Relocated training/evaluation command help worked from outside the repository.
- Documentation links and rename-aware diff whitespace checks passed.
- A separate read-only reviewer found one stale documentation output path, which was corrected.
- Full camera/database flows were not tested.
- At completion of organization, changes were local, unstaged, uncommitted, and unpushed. HEAD was still `88b6f4f`. Check current status rather than assuming this remains true.
- Original preservation inventory: `C:\Users\Administrator\AppData\Local\Temp\newcapstone-organization-inventory-20261028.json` (temporary external file; may not persist).

The user then requested saving this conversation in memory. This file records the useful project context; no persistent-memory tool was available in that session.

## Session update: 2026-10-30

This update supersedes earlier statements that live camera/database flows were untested. It summarizes decisions and completed work, not a verbatim transcript. Verify current configuration and processes before acting; the user frequently changes camera settings.

### Running the project and accounts

- App: http://localhost/NewCapstone/ ; detection service: detect_plate_server.py on port 5000.
- Python: C:/Users/Administrator/AppData/Local/Programs/Python/Python312/python.exe. PHP/MySQL tools: D:/xampp/php and D:/xampp/mysql/bin.
- Database: security_system, localhost port 3307. Use existing local configuration for credentials; passwords are intentionally omitted from this memory.
- Created administrator admin1 and guard josh (Josh, linked guard profile SG-0004). Preserve accounts when clearing vehicle logs.
- MySQL Aria corruption was repaired after a backup at D:/xampp/mysql/backup-before-aria-repair-20261030-112001. Three damaged mysql.db grant rows were lost during repair; user was informed.

### Camera and entry/exit decisions

- Tapo C260 entrance IP was 192.168.1.74. Credentials and sources belong in ignored camera_config.json; do not copy secrets into tracked documentation.
- RTSP stream2 (1280x720) improved latency compared with stream1 (4K). Measured preview improved from about 1.8 to 15.3 FPS and capture from 1.9 to 23.9 FPS during testing; these are observations, not guarantees.
- Support one shared camera or two separate cameras. Preserve the separate exit source when switching modes, so the second camera can be configured when it arrives.
- Final approved shared-camera AUTO behavior: no open visit means IN; after the plate leaves view and returns, an open visit means OUT. Holding a plate in view must not repeatedly alternate entry/exit. Manual IN/OUT selection remains available; dual cameras use fixed entrance IN and exit OUT.
- A proposed manual scan-confirmation plan was cancelled. Do not restore that proposal without a new request.
- Camera reconnect handling was changed so only the capture thread releases VideoCapture; this addresses native crashes when switching settings. Inspect current camera mode/source before changing them.

### Recognition and logging implementation

- Primary files: detect_plate_server.py, guard_dashboard.php, app/vehicle_visit_logger.py, lpr_engine/plate_tracker.py, lpr_engine/quad_ensemble.py, lpr_engine/reader_pool.py.
- Visit logging uses a transaction and per-plate database lock. AUTO resolves direction against an open visit. OUT closes time_out and original entry date_out; an unmatched OUT can create an exit-only record without fabricating entry time.
- Registered classification requires an active exact registration. Registration no longer forces OCR text to match a stored plate.
- Random untrained LPRNet is excluded when trained weights are missing. Paddle uses recognition-only inference; reader calls use a bounded pool and a four-second deadline. A previous short deadline discarded slow OCR results and caused REVIEW without logs; this was corrected.
- The user requested immediate acceptance for moving vehicles at 90%, then explicitly requested 80% and above. Current tracker fast_accept_confidence is 0.80 in lpr_engine/plate_tracker.py; Paddle-only reliable nomination threshold is also 0.80 in lpr_engine/quad_ensemble.py. Locate current lines instead of relying on old line numbers.
- Displayed confidence combines detector 40% and OCR 60%. It is not a guarantee of correct text. The tracker immediately emits an ACCEPT result at or above its threshold; below threshold it requires repeated agreement. Unrelated strong OCR disagreements remain review-only.
- Plate-presence suppression requires processed absent frames before reappearance; camera stalls alone do not count as departure. Presence is in memory and resets after restart.
- D566CP was successfully recognized and logged in live testing. The user requested removing its active registration for visitor testing; it was archived/deleted and registration cache refreshed. The user had also re-added it during testing, so check the database before assuming its current classification.
- Targeted tests cover camera modes/lifecycle, tracker, reader deadlines, recognition safety, and actual disposable-database visit transactions. Forty-nine tests passed before the final threshold change; twenty-six relevant tracker/recognition tests passed after the 80% change. Python compilation and diff whitespace checks passed. Changes were local and uncommitted; verify Git state before further work.

### Most recent reset requested by user

- User explicitly requested clearing all vehicle entry/exit logs for every account, including administrators. Logs are shared globally rather than belonging to each guard account.
- Completed transactional deletion from vehicle_log_dates, vehicle_logs, and vehicle_logs_archive. Reset visit date/time fields and status in visitor_vehicles and visitor_vehicles_archive while preserving vehicle identities, registrations, and all accounts.
- Latest reset removed 6 visit rows and 12 event rows; verified zero remaining log rows at reset. New camera detections can naturally create new records afterward.
- Backup: D:/xampp/htdocs/NewCapstone/scratch/security-system-before-all-accounts-log-reset-20261030-135604.sql.
- Detection service was restarted hidden afterward (supervisor PID 14480 at that moment; PID is historical). Readiness was not reverified after that reset. Runtime output is under scratch/camera-server.stdout.log and scratch/camera-server.stderr.log.
- DELETE retained auto-increment sequences for polling cursors. Browser notification history is separate from database logs; do not delete browser profiles to clear it.

The user requested saving this conversation. This project-local file provides durable handoff context; it is not account-wide assistant memory. No passwords are included in this update.

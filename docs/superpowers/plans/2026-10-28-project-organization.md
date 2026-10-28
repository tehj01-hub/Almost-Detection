# Project Organization Implementation Plan

> **For agentic workers:** Use the executing-plans workflow to implement these tasks in order.

**Goal:** Group supporting files by purpose and provide a debugging map without deleting files or changing application behavior.

**Architecture:** Keep public PHP pages and Python server launch files at the root. Move shared PHP code, JavaScript, checks, training material, SQL, and setup instructions into dedicated folders and update their references.

**Tech Stack:** PHP, JavaScript, Python, XAMPP, existing YOLOv10 and OCR libraries.

**Spec:** The user approved the folder structure proposed in this conversation on 2026-10-28.

## Global Constraints

- Preserve every existing file; use moves, not deletion.
- Preserve public PHP URLs, server launch commands, local settings, dependencies, datasets, and model locations.
- Leave changes local for review; do not commit or push this reorganization.
- Keep existing behavior; change only paths and documentation.

## Review Focus

- Every PHP include and browser script reference must resolve after the moves.
- Python checks and training scripts must still locate the root OCR package and weights.
- Test output must stay ignored by Git.
- Setup instructions must use the new test paths and portable links.
- Every original tracked file and moved local artifact must have a surviving destination.

## Task 1: Shared PHP and browser assets

- [x] Record an original-file inventory for preservation checks.
- [x] Move `session_helper.php` and `system_config.php` into `app/`.
- [x] Move both JavaScript files into `assets/js/`.
- [x] Update all root PHP includes and script references, plus relevant code comments.
- [x] Verify all PHP syntax, JavaScript syntax, and local include/script targets.

## Task 2: Checks, training, SQL, and setup documentation

- [x] Move the four `test_*.py` scripts into `tests/`; keep the OpenVINO test server at the root because it is a full server launch file.
- [x] Move `test_result.jpg` into ignored `tests/artifacts/` and update its producing check.
- [x] Move training Python scripts, notebooks, and `data.yaml` into `training/`.
- [x] Move `evaluate_ocr_models.py` into the existing `tools/` folder.
- [x] Correct root resolution in moved Python scripts; preserve user-supplied relative paths.
- [x] Move SQL into `database/`, setup notes into `docs/setup/`, and the existing placeholder `git` file into `docs/archive/`.
- [x] Update setup commands and links without changing notebook content or SQL.
- [x] Verify Python syntax, root resolution, and unchanged hashes for files requiring no content edits.

## Task 3: Debugging guide and final verification

- [x] Add `docs/PROJECT_MAP.md` with a folder tree, old-to-new paths, feature-to-file map, and launch/check commands.
- [x] Link the guide from the root README while preserving its original text.
- [x] Run existing offline OCR checks where installed dependencies allow; do not contact cameras or external APIs.
- [x] Check file preservation, ignore rules, path references, and the final diff; report any runtime verification limits.

## Execution decisions

The user's approval authorizes this exact folder organization in the current workspace. Implement directly without another approval round, installing dependencies, creating a worktree, committing, or publishing. Use syntax checks and path/preservation checks for this structural change rather than adding tests that merely repeat the move list.


## Verification record

- All 57 original inventory files (56 tracked files and the local test image) exist at their final paths. The model servers, OCR engine, SQL, notebooks, dataset YAML, and image retain their original bytes.
- All 18 application PHP files pass syntax checks; local includes and browser script targets resolve. Shared helper loading and sample pure helper calls pass.
- Both moved JavaScript files pass Node syntax checks and return their exact content over HTTP from a temporary PHP server.
- All 23 project Python files pass AST syntax checks. Each relocated local Python script resolves the original project root for imports and resources.
- The existing offline LPRNet checks pass before and after the moves (five test sections).
- Training and evaluation `--help` commands succeed when launched from outside the repository.
- Documentation links resolve; test output remains ignored; a temporary-index review recognizes every tracked move as a rename and reports no diff whitespace errors.
- The ignored `scratch/test_save_visitor.php` includes were updated to `../app/` so the preserved experiment retains access to its helpers.
- Live camera, database, external OCR/API, and complete browser flows were not exercised by this organization task.
- No dependencies were installed and no changes were committed or pushed.
- Final independent review found one stale image-output instruction; corrected it and converted the remaining setup file links to relative paths. No broken runtime paths, lost files, or behavior changes were reported.

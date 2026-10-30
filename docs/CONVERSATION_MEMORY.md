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

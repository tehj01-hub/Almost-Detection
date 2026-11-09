@echo off
REM Weekly automatic database backup + delete backups older than 6 months.
REM Point Windows Task Scheduler at this file (e.g. weekly, Sunday 02:00).
REM Add --force to make a backup immediately.
cd /d "%~dp0.."
"C:\xampp\php\php.exe" "%~dp0run_auto_backup.php" %*

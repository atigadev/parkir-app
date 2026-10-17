@echo off
REM Backup harian database ParkIt (Windows / XAMPP). Jadwalkan via Task Scheduler.
REM Isi backup sama dengan cli\backup.php di cPanel. Ubah folder tujuan lewat 'backup_dir' di config.php
REM (sebaiknya disk lain / NAS), atau tambahkan argumen: --ke=D:\BackupParkit
"C:\xampp\php\php.exe" "%~dp0backup.php" %*
exit /b %errorlevel%

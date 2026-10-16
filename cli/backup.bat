@echo off
REM Backup harian database ParkIt. Jadwalkan via Task Scheduler (lihat README).
REM Ubah TUJUAN ke disk lain / NAS agar backup tidak ikut hilang bila disk server rusak.
setlocal
set TUJUAN=C:\xampp\htdocs\parkit-app\backup
set MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe
set DB=parkit
set USER=root
set PASS=

if not exist "%TUJUAN%" mkdir "%TUJUAN%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmm"') do set STAMP=%%i

if "%PASS%"=="" (
  "%MYSQLDUMP%" -u %USER% --single-transaction --routines %DB% > "%TUJUAN%\parkit-%STAMP%.sql"
) else (
  "%MYSQLDUMP%" -u %USER% -p%PASS% --single-transaction --routines %DB% > "%TUJUAN%\parkit-%STAMP%.sql"
)
if errorlevel 1 (
  echo Backup GAGAL
  exit /b 1
)

REM Simpan 30 backup terakhir
powershell -NoProfile -Command "Get-ChildItem '%TUJUAN%\parkit-*.sql' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 30 | Remove-Item"
echo Backup selesai: %TUJUAN%\parkit-%STAMP%.sql
endlocal

@echo off
setlocal
REM Configure AICS_ADCD_PYTHON_EXECUTABLE and AICS_ADCD_NET_ROOT in the
REM server environment. This wrapper derives only its own repository path.
if "%AICS_ADCD_PYTHON_EXECUTABLE%"=="" echo AICS_ADCD_PYTHON_EXECUTABLE is not configured.& exit /b 1
if "%AICS_ADCD_NET_ROOT%"=="" echo AICS_ADCD_NET_ROOT is not configured.& exit /b 1
set "ADCD_PYTHON=%AICS_ADCD_PYTHON_EXECUTABLE%"
set "ADCD_SERVICE=%~dp0service.py"
if "%AICS_ADCD_OUTPUT_ROOT%"=="" set "AICS_ADCD_OUTPUT_ROOT=%AICS_ADCD_NET_ROOT%\test_outputs\service"

if not exist "%ADCD_PYTHON%" echo ADCD-Net Python environment was not found.& exit /b 1
if not exist "%ADCD_SERVICE%" echo ADCD-Net service module was not found.& exit /b 1
if not exist "%AICS_ADCD_NET_ROOT%\aics_inference.py" echo ADCD-Net repository is not valid.& exit /b 1
if not exist "%AICS_ADCD_OUTPUT_ROOT%" mkdir "%AICS_ADCD_OUTPUT_ROOT%" 2>nul
if not exist "%AICS_ADCD_OUTPUT_ROOT%" echo ADCD service output directory is unavailable.& exit /b 1

netstat -ano | findstr /R /C:":5001 .*LISTENING" >nul
if errorlevel 1 (
  start "AICS ADCD-Net service" /b "%ADCD_PYTHON%" "%ADCD_SERVICE%"
  echo Service started locally.
) else (
  echo A process is already listening on 127.0.0.1:5001. Verifying the ADCD-Net health and model state...
)

echo Waiting for 127.0.0.1:5001, then requiring a loaded model...
powershell -NoProfile -Command "$deadline=(Get-Date).AddSeconds(45); do { try { $health=Invoke-RestMethod -Method Get -TimeoutSec 3 http://127.0.0.1:5001/health; if ($health.service -ne 'aics-adcd-net') { exit 1 }; if ($health.model_loaded -ne $true) { $load=Invoke-RestMethod -Method Post -TimeoutSec 90 http://127.0.0.1:5001/load; if ($load.success -ne $true -or $load.model_loaded -ne $true) { exit 1 } }; $verified=Invoke-RestMethod -Method Get -TimeoutSec 3 http://127.0.0.1:5001/health; if ($verified.status -eq 'ready' -and $verified.model_loaded -eq $true) { $verified | ConvertTo-Json -Compress; exit 0 }; exit 1 } catch { Start-Sleep -Milliseconds 500 } } while ((Get-Date) -lt $deadline); exit 1"
if errorlevel 1 echo ADCD-Net service is not ready with a loaded model. Review the local service log.& exit /b 1
echo ADCD-Net is ready on localhost only.

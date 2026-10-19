@echo off
setlocal

REM Deployment values are server environment variables. The wrapper derives
REM only its own project location and never embeds a machine-specific path.
if "%AICS_PYTHON_EXECUTABLE%"=="" exit /b 1
if "%AICS_PROJECT_ROOT%"=="" set "AICS_PROJECT_ROOT=%~dp0.."
if "%AICS_ADCD_ENABLED%"=="" set "AICS_ADCD_ENABLED=0"
REM cmd.exe SET chains can leave padding in inherited values on Windows.
REM Python intentionally accepts only the exact value "1".
set "AICS_ADCD_ENABLED=%AICS_ADCD_ENABLED: =%"
REM Strip padding only from values that are set; "%UNSET: =%" would become " =".
if defined AICS_ADCD_SERVICE_URL set "AICS_ADCD_SERVICE_URL=%AICS_ADCD_SERVICE_URL: =%"
if defined ADCD_CONNECT_TIMEOUT set "ADCD_CONNECT_TIMEOUT=%ADCD_CONNECT_TIMEOUT: =%"
if defined ADCD_READ_TIMEOUT set "ADCD_READ_TIMEOUT=%ADCD_READ_TIMEOUT: =%"
REM The isolated test database selection must also reach Python exactly.
if defined AICS_HTTP_TEST_MODE set "AICS_HTTP_TEST_MODE=%AICS_HTTP_TEST_MODE: =%"
if defined AICS_TEST_DB_NAME set "AICS_TEST_DB_NAME=%AICS_TEST_DB_NAME: =%"
if "%AICS_ADCD_SERVICE_URL%"=="" set "AICS_ADCD_SERVICE_URL=http://127.0.0.1:5001"
set "AICS_WORKER_PYTHON=%AICS_PYTHON_EXECUTABLE%"
set "AICS_WORKER_ROOT=%AICS_PROJECT_ROOT%"

if not exist "%AICS_WORKER_PYTHON%" exit /b 1
if not exist "%AICS_WORKER_ROOT%\ai_processor\processor.py" exit /b 1

cd /d "%AICS_WORKER_ROOT%"
"%AICS_WORKER_PYTHON%" -m ai_processor.processor %*
exit /b %ERRORLEVEL%

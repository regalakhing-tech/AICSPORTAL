@echo off
rem Local XAMPP only. On Railway, deploy/start.sh runs Smart Priority (AICS AI
rem medical urgency) every 2 minutes. XAMPP has no such loop, so without this
rem window new medical requests stay "Standard, still checking".
rem Keep this window open (minimized is fine) while using the portal locally.
title AICS Smart Priority (local)
cd /d "%~dp0.."
set "PHP=php"
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
:loop
"%PHP%" tools\smart-priority-refresh.php --quiet
timeout /t 120 /nobreak >nul
goto loop

@echo off
REM =============================================================================
REM Risk Register — schedule exception monitor + email sender in Task Scheduler
REM =============================================================================
REM Usage:
REM   schedule-exception-workers.bat
REM   schedule-exception-workers.bat install [intervalMinutes]
REM   schedule-exception-workers.bat verify
REM   schedule-exception-workers.bat status
REM   schedule-exception-workers.bat run-once
REM   schedule-exception-workers.bat uninstall
REM
REM Default interval: 60 minutes (exceptions are date-based).
REM =============================================================================

setlocal EnableExtensions
cd /d "%~dp0.."

set "ACTION=%~1"
if "%ACTION%"=="" set "ACTION=install"

if /I "%ACTION%"=="help" goto :help
if /I "%ACTION%"=="/?" goto :help
if /I "%ACTION%"=="-h" goto :help
if /I "%ACTION%"=="--help" goto :help

set "INTERVAL=%~2"
if "%INTERVAL%"=="" set "INTERVAL=60"

if /I not "%ACTION%"=="install" if "%~2"=="" set "INTERVAL=60"

echo.
echo Risk Register exception scheduler
echo   Action:   %ACTION%
echo   Interval: %INTERVAL% minute(s)  (install only)
echo   Root:     %CD%
echo.

where powershell >nul 2>&1
if errorlevel 1 (
  echo [FAIL] powershell.exe not found on PATH.
  set "ERR=1"
  goto :done
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0schedule-exception-workers.ps1" -Action "%ACTION%" -IntervalMinutes %INTERVAL%
set "ERR=%ERRORLEVEL%"

:done
echo.
if not "%ERR%"=="0" (
  echo Failed with exit code %ERR%.
  echo Tip: run "schedule-exception-workers.bat verify" after fixing issues.
) else (
  echo Completed successfully.
)
echo.
pause
endlocal & exit /b %ERR%

:help
echo.
echo Risk Register exception Task Scheduler helper
echo.
echo   schedule-exception-workers.bat                 Install + verify + smoke test
echo   schedule-exception-workers.bat install 60      Install repeating every 60 minutes
echo   schedule-exception-workers.bat verify          Confirm both tasks are scheduled correctly
echo   schedule-exception-workers.bat status          Short LastRun / NextRun / State report
echo   schedule-exception-workers.bat run-once        Run monitor + email workers once now
echo   schedule-exception-workers.bat uninstall       Remove both scheduled tasks
echo.
echo Creates:
echo   - "RiskRegister - Exception Monitor"   (due exceptions + queue emails)
echo   - "RiskRegister - Email Sender"        (SMTP drain from email_outbox)
echo.
endlocal & exit /b 0

@echo off
REM ===============================================================
REM  MAEXX - start the local mail server (Mailpit)
REM ===============================================================
REM  Run this before testing forgot password or signup, so the
REM  system can deliver verification codes.
REM
REM    SMTP  ->  127.0.0.1:1025   (what MAEXX sends to)
REM    Inbox ->  http://localhost:8025   (where you read the mail)
REM
REM  Keep this window open while you work. Close it, or press
REM  Ctrl+C, to stop the mail server.
REM
REM  Nothing is sent to the internet - every message stays on
REM  this computer.
REM ===============================================================

title MAEXX Mail Server (Mailpit)

set MAILPIT="%LOCALAPPDATA%\Microsoft\WinGet\Packages\axllent.mailpit_Microsoft.Winget.Source_8wekyb3d8bbwe\mailpit.exe"

if not exist %MAILPIT% (
    echo.
    echo  Mailpit was not found at:
    echo  %MAILPIT%
    echo.
    echo  Reinstall it with:  winget install axllent.mailpit
    echo.
    pause
    exit /b 1
)

echo.
echo  Starting the MAEXX mail server...
echo.
echo    Inbox:  http://localhost:8025
echo    SMTP:   127.0.0.1:1025
echo.
echo  Leave this window open. Press Ctrl+C to stop.
echo.

start "" http://localhost:8025

%MAILPIT% --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025

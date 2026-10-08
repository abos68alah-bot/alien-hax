@echo off
title Alien Hax - Local Server
color 0A

echo.
echo  ==========================================
echo    ALIEN HAX - Starting Local Server...
echo  ==========================================
echo.

:: Kill any existing PHP server on port 8080
for /f "tokens=5" %%a in ('netstat -aon ^| find ":8080" ^| find "LISTENING" 2^>nul') do (
    taskkill /PID %%a /F >nul 2>&1
)

:: Navigate to project directory
cd /d "%~dp0"

:: Start PHP built-in server in background
echo  [*] Starting PHP server on http://localhost:8080 ...
start "" /B php -S localhost:8080 router.php

:: Wait 2 seconds for server to start
timeout /t 2 /nobreak >nul

:: Open browser
echo  [*] Opening browser...
start "" "http://localhost:8080"

echo.
echo  ==========================================
echo    Website is running at:
echo    http://localhost:8080
echo.
echo    Admin Panel:
echo    http://localhost:8080/admin-login
echo.
echo    Admin Username : Alien
echo    Admin Password : Alien2024
echo  ==========================================
echo.
echo  [Press any key to STOP the server]
echo.
pause >nul

:: Stop PHP server when user presses a key
echo  Stopping server...
for /f "tokens=5" %%a in ('netstat -aon ^| find ":8080" ^| find "LISTENING" 2^>nul') do (
    taskkill /PID %%a /F >nul 2>&1
)
echo  Server stopped. Goodbye!
timeout /t 2 /nobreak >nul

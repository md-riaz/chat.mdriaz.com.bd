@echo off
title Chat WebSocket Server
echo Starting Chat WebSocket Server...
echo.
echo Server will be available at: ws://localhost:8080/chat
echo Press Ctrl+C to stop the server
echo.

cd /d "%~dp0"
php bin\chat-server.php

pause

@echo off
title Bomber Arena - Tunel Cloudflare
echo ========================================================
echo   BOMBER ARENA - TUNEL CLOUDFLARE (WebSockets + HTTP)
echo ========================================================
echo.
echo Iniciando Gateway local (puerto 8090)...
start "Gateway Tunel" /min cmd /c "node tunnel-gateway.cjs"
timeout /t 2 >nul
echo.
echo Conectando con la red global de Cloudflare...
echo Tu URL publica aparecera debajo (ej: https://xxx.trycloudflare.com)
echo Copiala y compartela con tus amigos o alumnos para jugar!
echo.
.\cloudflared.exe tunnel --url http://127.0.0.1:8090
pause

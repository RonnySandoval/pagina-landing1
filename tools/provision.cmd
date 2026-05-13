@echo off
setlocal
REM Lanza provision.ps1 y deja la ventana abierta con "pause" al terminar.
REM Asi puedes leer errores o el resumen si doble clic o acceso directo cierra la consola demasiado pronto.
cd /d "%~dp0"
set "PROVISION_FROM_BATCH=1"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0provision.ps1" %*
set "ERR=%ERRORLEVEL%"
echo.
if not "%ERR%"=="0" echo Codigo de salida: %ERR%
pause
exit /b %ERR%

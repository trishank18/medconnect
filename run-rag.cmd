@echo off
setlocal
cd /d "%~dp0"
title MedConnect RAG API

curl.exe --fail --silent http://127.0.0.1:8000/health >nul 2>&1
if not errorlevel 1 (
    echo MedConnect RAG API is already running at http://127.0.0.1:8000
    exit /b 0
)

if exist "python-rag\.venv\Scripts\python.exe" (
    "python-rag\.venv\Scripts\python.exe" -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
    goto :startup_failed
)

where py >nul 2>&1
if not errorlevel 1 (
    py -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
    goto :startup_failed
)

where python >nul 2>&1
if not errorlevel 1 (
    python -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
    goto :startup_failed
)

echo Python was not found. Install Python, then run: python -m pip install -r python-rag\requirements.txt
goto :failed

:startup_failed
echo The RAG service stopped or failed to start.
echo If dependencies are missing, run: python -m pip install -r python-rag\requirements.txt

:failed
pause
exit /b 1

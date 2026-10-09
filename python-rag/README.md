# MedConnect RAG service

This local FastAPI service indexes approved PDF/TXT educational or MedConnect technical references. PDF text is extracted with PyMuPDF; scanned pages are not OCR processed. Vectors use `sentence-transformers/all-MiniLM-L6-v2` (384 dimensions), normalized to unit length and searched with FAISS inner product, equivalent to cosine similarity. The index and chunk metadata are local under `data/` and ignored by Git.

The browser never calls this service. `gemini-chat.php` checks the existing PHP patient/doctor session and calls `/chat` using the server-only `RAG_SERVICE_TOKEN`. PHP keeps patient readings in parameterized MySQL queries; those records are never sent to Gemini. Doctor record requests must name a patient selected from the existing assigned-patient list and are re-authorized against `appointments` on every request. This is a student informational feature, not a validated diagnostic system.

## Windows / XAMPP setup

1. Create a Google AI Studio API key at [Google AI Studio](https://aistudio.google.com/app/apikey). Do not paste it into source files or chat.
2. Copy the RAG variables from `.env.example` into the project root `.env` (which is already excluded from Git). Set `GEMINI_API_KEY`; generate a long random `RAG_SERVICE_TOKEN` and put the same value in PHP's environment and the RAG service environment. Keep `RAG_URL=http://127.0.0.1:8000/chat`.
3. From the repository root, install dependencies and launch the service:

   ```powershell
   py -m venv python-rag/.venv
   .\python-rag\.venv\Scripts\Activate.ps1
   py -m pip install -r python-rag/requirements.txt
   py -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
   ```

   Keep the service bound to loopback on local XAMPP. PHP's `curl` extension must be enabled. The service loads root `.env` at startup.
4. Place approved PDFs/TXT files only in `python-rag/documents/`. Rebuild the local index whenever references change:

   ```powershell
   py python-rag/ingest.py
   ```

   The command prints document, chunk, and extraction-error counts. Rebuilding replaces the index, so removed/changed documents are removed from the next index. Review each source before adding it; retrieval content is treated as untrusted data. No public ingestion route is provided.
5. Start Apache/MySQL in XAMPP and use the existing MedConnect dashboards. `GET http://127.0.0.1:8000/health` reports only service liveness.

The default model is `gemini-3.8-flash`; the model page documents it as generally available. Change `GEMINI_MODEL` through environment configuration when Google changes model availability. Missing/invalid keys, quota errors, timeouts, and service failures are returned as a sanitized temporary-unavailability message. [Google Gemini model documentation](https://ai.google.dev/gemini-api/docs/models?hl=en)

## Manual checklist

- Sign in as patient and doctor; confirm both existing dashboards still render when FastAPI is stopped.
- Ask an approved-document question with a relevant ingested page and check the document/page source displayed below the answer.
- Ask an unsupported knowledge question and confirm the abstention response.
- Ask a patient for their latest readings; check timestamps and units against `health_metrics`.
- As a doctor, select an assigned patient and request readings. Then tamper with `patient_id` in the request and confirm an unassigned ID returns 403.
- Remove `GEMINI_API_KEY`, use an invalid key, and stop FastAPI; confirm the dashboard stays available and only sanitized errors appear.
- Check mobile layout, keyboard submission, and that response text is rendered as text rather than HTML.

Automated verification is limited to syntax checks unless the optional Python dependencies and local MySQL/XAMPP services are installed. No live account credentials or real patient data are needed for reference-answer testing.

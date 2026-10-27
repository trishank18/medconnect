# MedConnect RAG and Health Assistant

The browser posts to `gemini-chat.php`. PHP validates the CSRF token and authenticated patient/doctor session, handles private records with parameterized MySQL queries, verifies doctor assignment for each requested patient, and calls the local Python service only for approved-document or general questions. PHP sends `question`, a random `request_id`, a server-validated per-account `conversation_id`, and the server-only `X-MedConnect-Token`. The Python service returns `{reply, category, sources}`. PHP forwards the reply and source references; the browser renders them with `textContent`.

### HTTP contract

`GET /health` returns only `{"status":"ok"}`. `POST /chat` requires `Content-Type: application/json` and `X-MedConnect-Token`:

```json
{
  "question": "What is MedConnect?",
  "request_id": "random-request-id",
  "conversation_id": "64-lowercase-hex-characters"
}
```

Successful responses contain `reply`, `category`, and `sources` (`document`, optional `page`, optional `section`). The route returns 401 for missing/invalid service authentication, 403 for private or ambiguous records, 422 for malformed input, and a sanitized 503 for Gemini generation failures. The API rejects extra request fields, including browser-supplied patient identifiers.

## Query routing and privacy boundary

The Python `routing.py` classifier uses conservative local rules; it does not make an extra Gemini classification request:

| Category | Route |
| --- | --- |
| `MEDCONNECT_RAG` | Search approved technical/application documents. Generate from excerpts and return source metadata. If there is no match, answer that the project detail cannot be verified; do not guess. |
| `PATIENT_DATA` | Deny at the RAG API. PHP answers using the authenticated patient's ID and MySQL. |
| `DOCTOR_DATA` | Deny at the RAG API. PHP must check the approved doctor's assignment/appointment relationship before reading any selected patient's records. |
| `GENERAL_AI` | Call Gemini directly; no vector search is required. |
| `AMBIGUOUS_OR_UNAUTHORIZED` | Refuse or ask the user to select an authorized patient in MedConnect. |

Patient measurements, appointments, and prescriptions are never added to the vector index or Python conversation history. Conversation history in Python is process-local, bounded to the configured number of turns, expires, and is keyed by PHP's opaque per-role/account ID. Private data routes return from PHP before the RAG request. Retrieved documents and prior user text are treated as untrusted input. Responses are informational and not a diagnosis or substitute for a clinician.

## Requirements and Windows startup

Use Python 3.11 or newer, XAMPP PHP with PDO MySQL and cURL enabled, and the existing `medconnect` MySQL schema. No schema migration is required. Do not replace the existing root `.env`.

1. Copy the needed placeholder values from the repository root `.env.example` to the root `.env`. Set `GEMINI_API_KEY` from Google AI Studio. Set a long random `RAG_SERVICE_TOKEN`; the same value must be available to PHP and Python. Keep all secrets server-side.
2. In PowerShell from the project root:

   ```powershell
   py -3.11 -m venv python-rag\.venv
   .\python-rag\.venv\Scripts\Activate.ps1
   python -m pip install --upgrade pip
   python -m pip install -r python-rag\requirements.txt
   python -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
   ```

   Keep this bound to loopback on local XAMPP. `/health` is a liveness route and does not disclose key/index state.
3. In XAMPP, start Apache and MySQL. Visit `http://localhost/medconnect/`. Ensure PHP reads `RAG_SERVICE_TOKEN` and `RAG_URL=http://127.0.0.1:8000/chat` from the same server environment. For Apache, configure PHP environment values in its process configuration; do not put secrets in browser JavaScript.
4. Add only reviewed, non-sensitive PDF/TXT references to `python-rag\documents`. Rebuild the index from the project root:

   ```powershell
   .\python-rag\.venv\Scripts\python.exe python-rag\ingest.py
   ```

   Ingestion rebuilds the FAISS index and metadata from the approved directory. It emits stable document/chunk IDs and preserves the existing index if a file or embedding fails. Scanned PDFs have no OCR. Never add patient records to this global corpus.

## Environment variables

Names below are the values read by the existing PHP/Python integration. Examples are placeholders only; do not commit real values.

| Variable | Purpose |
| --- | --- |
| `GEMINI_API_KEY` | Server-side Google AI Studio key. |
| `GEMINI_MODEL` | Gemini API model ID; default `gemini-3.8-flash`. |
| `GEMINI_TIMEOUT_MS` | Per-request provider timeout; default 20000. |
| `GEMINI_RETRIES` | Bounded retries for rate/transient errors; default 2. |
| `EMBEDDING_MODEL` | Existing Sentence Transformers model; default `sentence-transformers/all-MiniLM-L6-v2`. Changing it requires re-ingestion. |
| `RAG_SERVICE_TOKEN` | Shared server-only PHP-to-Python bearer-like header secret. |
| `RAG_URL` | PHP URL for `/chat`; default `http://127.0.0.1:8000/chat`. |
| `RAG_HOST`, `RAG_PORT` | Startup-helper host/port settings (the explicit Uvicorn command above binds loopback). |
| `RAG_TOP_K`, `RAG_MIN_SIMILARITY` | Retrieval result count and cosine threshold. |
| `RAG_CHUNK_SIZE`, `RAG_CHUNK_OVERLAP`, `RAG_EMBED_BATCH_SIZE` | Ingestion behavior. |
| `RAG_CONVERSATION_TTL_SECONDS`, `RAG_MAX_HISTORY_TURNS` | Bounded in-memory, non-private follow-up context. |
| `RAG_DOCUMENTS_DIR`, `RAG_DATA_DIR` | Optional paths for approved documents and local index files. |

The service uses the official `google-genai` Python SDK. Model names are not hardcoded in requests; availability for a configured key/account can be checked with the optional live test. The current configured default `gemini-3.8-flash` appears in Google's current Gemini API model guide. See [Gemini API models](https://ai.google.dev/gemini-api/docs/models) and [Google Gen AI Python SDK](https://googleapis.github.io/python-genai/).

## Tests

Run the no-paid-call suite from the root:

```powershell
python -m unittest discover -s python-rag/tests -v
```

It uses mocked Gemini requests and covers routing, source behavior, malformed input, identity-data denial at the Python boundary, transient/key/model errors, conversation isolation/bounds, the PHP/Python payload contract, chunk metadata, index model compatibility, and retrieval ranking. The optional paid/live model test is skipped unless the key is present; run separately only when desired:

```powershell
$env:RUN_LIVE_GEMINI = '1'
python -m unittest python-rag.tests.test_live_gemini -v
```

PHP syntax can be checked with:

```powershell
C:\xampp\php\php.exe -l gemini-chat.php
```

The Python API does not expose document ingestion or a public vector search route. No reranker is installed; no local relevance dataset exists to establish that reranking helps. Retrieval uses normalized dense vectors plus lexical reciprocal-rank fusion. Re-ingestion replaces the complete local index to remove stale chunks. Conversation cache is in-memory and resets when the service restarts or when its TTL expires.

## Troubleshooting

- **PHP says assistant unavailable:** confirm Apache's process can read `RAG_SERVICE_TOKEN`, `RAG_URL` points to `127.0.0.1:8000/chat`, PHP cURL is enabled, and `GET http://127.0.0.1:8000/health` responds.
- **Python returns 401:** PHP and Python have different `RAG_SERVICE_TOKEN` values, or the token is unset. Rotate/set them on both server processes; never put the token in JavaScript.
- **General answers unavailable:** check `GEMINI_API_KEY`, account quota, network access, configured model availability, and the Python process logs. User-facing errors are sanitized.
- **MedConnect answer cannot be verified:** check the approved document is in `python-rag\documents`, re-run ingestion, and confirm the text is extractable. Scanned pages need OCR before they can be indexed.
- **Index/model mismatch:** keep `EMBEDDING_MODEL` unchanged or rebuild through the ingestion command. Do not manually edit FAISS files or reuse vectors from another model.
- **Doctor cannot see readings:** confirm the doctor is approved, a patient is selected in the doctor workspace, and the patient is assigned or has an appointment with that doctor. PHP performs this check on every request.

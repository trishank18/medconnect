# MedConnect API and Handler Contracts

These are existing routes, not a newly versioned REST API. The browser talks to
the PHP application; only PHP calls private local Python services. API calls
must not include service secrets in frontend code.

## PHP routes

| Route | Method / identity | Contract and behavior |
|---|---|---|
| `csrf-token.php?form=...` | GET, same-origin session | Allows known form names and returns JSON with a session CSRF token. Do not log or publish the token. |
| `save_data.php` | POST, device token | `X-MedConnect-Device-Token`; form fields `heart_rate`, `spo2`, `temperature`. Patient target comes from server `DEVICE_PATIENT_ID`. Bounds are validated; success is text, errors use 401/405/422/500. |
| `get_patient_metrics.php?patient_id=...` | GET, approved doctor session | Returns `{success, metrics}` for a doctor-assigned or appointment-authorized patient; 401/403/422/500 otherwise. |
| `predict_health.php` | GET, patient session | Reads the current patient's stored readings and runs the retained local prediction script; returns JSON, with generic 401/500 errors. |
| `gemini-chat.php` | POST, patient or approved doctor session + `X-CSRF-Token` | JSON body includes `role`, `question`, `session_id`, and a `patient_id` only for doctor-selected patient requests. PHP validates the session/role, CSRF, size/rate, and doctor assignment before private queries. Response includes `reply`, optional `sources`, or a safe `error`. |
| `admin-action.php`, `admin-delete-patient.php` | POST, admin session + admin CSRF | Compatibility endpoints that load `admin/actions/admin-action.php` and `admin/actions/delete-patient.php`; approve/reject doctor or delete selected patient through server-side authorization. These are state-changing operations; not exercised in this audit. |
| `export-data.php?table=...` | GET, admin session | Streams only allow-listed export tables; export storage is not exposed as a public download directory. |
| Login/registration/appointment/profile handlers | Existing HTML form methods | Keep their existing routes and server-side validation; use session/role checks, prepared queries, and CSRF where implemented. |

## Python service routes

### RAG — `127.0.0.1:8000`

- `GET /health` returns `{"status":"ok"}` as a liveness response.
- `POST /chat` requires JSON and `X-MedConnect-Token`. PHP sends `question`,
  opaque `request_id`, and per-account `conversation_id`. It does not send
  patient records or browser-provided patient identity to the RAG service.
- Success response: `{reply, category, sources}`. A source can contain
  `document` and optional `page`/`section`. Errors are 401 service-token,
  403 private/ambiguous query, 422 malformed input, or sanitized 503 provider
  failure.

### ML — `127.0.0.1:8001`

- `GET /health` is liveness/model configuration status.
- `GET /model-info` requires `X-ML-Token`.
- `POST /predict/patient/{patient_id}` requires `X-ML-Token`; optional
  `history_limit` is bounded. PHP's session authorization must be checked
  before forwarding a patient ID. The ML service token authenticates PHP only;
  it does not grant a browser permission to access patient records.

## Error and privacy behavior

Use loopback host binding for Python services. Keep `RAG_SERVICE_TOKEN`,
`ML_API_TOKEN`, `DEVICE_INGEST_TOKEN`, Gemini keys, and DB credentials
server-side. PHP returns sanitized errors; details belong in protected server
logs. Never index health records into the public RAG corpus. See
`python-rag/README.md` and `ml/README.md` for precise service configuration.

# MedConnect Troubleshooting

## XAMPP startup

1. Open the XAMPP Control Panel and start Apache and MySQL.
2. Verify `http://localhost/medconnect/` loads.
3. Keep Python services bound to `127.0.0.1`; do not expose development Uvicorn
   servers directly to the internet.

PowerShell service commands and environment setup are documented in the root
[README](../README.md), [RAG guide](../python-rag/README.md), and
[ML guide](../ml/README.md).

## Database connection errors

- Confirm MySQL is running and that the intended `medconnect` schema exists.
- Check the server environment has `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
  and `DB_PASSWORD`; `DB_CONNECT_TIMEOUT` is bounded to 1–30 seconds.
- Restart Apache after changing process-level environment values.
- Do not paste credentials into PHP or replace a working `.env` blindly.
- A generic browser database-unavailable message is expected; inspect protected
  Apache/PHP error logs without copying passwords, SQL data, or PHI into a
  ticket.
- Import `database/setup.sql` only into a new empty local database. Do not use
  it to repair or reset an existing database.

## PHP route, session, or CSRF problems

- Use the same `http://localhost/medconnect/` base path that the forms expect;
  direct-file URLs and POST handlers should remain under that installation.
- Refresh an expired form to obtain its same-origin CSRF token. Ensure browser
  cookies are enabled and the session cookie is not blocked.
- A doctor must be approved. Doctor vital requests also require an assignment
  or appointment relationship. A patient cannot select another patient's ID.
- Admin actions require the admin session and CSRF token; do not bypass these
  checks to make a request succeed.

## RAG assistant errors

- Confirm Python dependencies are installed in `python-rag\.venv` and
  `GET http://127.0.0.1:8000/health` returns a liveness response.
- Ensure Apache and Python read the same `RAG_SERVICE_TOKEN` and PHP's
  `RAG_URL` points to `http://127.0.0.1:8000/chat`.
- General answers require valid server-only Gemini configuration and network
  access. Check Python logs for provider details; browser errors are sanitized.
- For missing MedConnect sources, add only reviewed, non-sensitive documents
  to `python-rag/documents/` and rerun the documented ingestion command.
- Do not add health exports, credentials, or patient chat history to the
  knowledge corpus.

## ML or device issues

- ML service uses `ml\.venv`, `ML_API_TOKEN`, and loopback port 8001. Verify
  `/health`, saved artifacts in `ml/models/`, DB configuration, and
  `X-ML-Token` between PHP and Python.
- Sensor ingestion expects POST form values and
  `X-MedConnect-Device-Token`. `DEVICE_PATIENT_ID` must be explicitly mapped
  server-side. Invalid token, missing mapping, or out-of-range values are
  rejected.
- Prediction outages must not prevent an actual health reading from being
  stored; the reading and model estimate are separate records.

See [API documentation](API_DOCUMENTATION.md) for routes and error behavior.

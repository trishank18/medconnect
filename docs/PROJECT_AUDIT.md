# MedConnect Project Audit

Audit date: 2026-10-27
Project: `C:\xampp\htdocs\medconnect`

This is the concise architecture and status index. The detailed file-by-file
coverage, verified findings, test evidence, risks, and implementation history
are in [FULL_PROJECT_AUDIT.md](FULL_PROJECT_AUDIT.md). The organization and
move evidence is in [project-organization-audit.md](project-organization-audit.md).

## Current structure

```text
MedConnect/
├── admin PHP endpoints and HTML login at the root (legacy URLs retained)
├── doctor/                 doctor login/registration handlers
├── patient/                patient login/registration handlers
├── includes/               shared PHP session, PDO, auth, ML, RAG, SMS helpers
├── css/, js/               shared styles and browser behavior
├── database/               new-install schema and legacy migration
├── ml/                     FastAPI inference, training, tests, models, reports
├── python-rag/              FastAPI RAG, ingestion, retrieval, docs, tests
├── dataset/                 model training data (HTTP denied)
├── exports/                 generated exports (HTTP denied)
├── tests/                   shared test dependency declaration
├── docs/                    audits, setup guides, device sample
└── root web routes          public pages, dashboards, forms, PHP APIs
```

Virtual environments, caches, ignored `.env` files, generated indexes, model
artifacts, and exports are not treated as source modules. Existing root-level
routes remain because HTML forms, redirects, browser scripts, and the ESP32
sample use those URLs. Moving them without a compatibility layer would break
the running application.

## Architecture and flows

- **Web:** Apache/XAMPP serves HTML and PHP; PHP sessions establish roles and
  identity. `config/database.php` is the sole PHP PDO constructor and
  `includes/db_connection.php` is a backward-compatible include shim. It uses
  `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and
  `DB_CONNECT_TIMEOUT` from server configuration.
- **Authentication:** public login/registration pages post to separate patient,
  doctor, and admin handlers. Successful users reach their role dashboard;
  protected pages check the session server-side. Doctors require approval.
- **Records:** PHP uses prepared MySQL queries. Patient endpoints scope records
  to the session patient. Doctor vital access checks approval and patient
  assignment/appointment authorization. Admin actions use the admin session and
  CSRF token.
- **Sensor:** ESP32 sends a POST to `save_data.php` with
  `X-MedConnect-Device-Token`; the patient target is configured server-side by
  `DEVICE_PATIENT_ID`, not trusted from the request.
- **ML:** PHP's ML client calls the loopback ML FastAPI service with
  `X-ML-Token`. The service reads authorized patient history from MySQL and
  predicts using saved artifacts. It does not overwrite actual measurements.
- **RAG/chat:** browser → `gemini-chat.php` (session, role, CSRF, rate and
  authorization checks) → either scoped PHP/MySQL private-data queries or the
  loopback Python RAG `/chat` route. RAG handles approved reference documents
  and general questions; private records are not indexed or sent to Gemini.

## Database and security status

The database remains `medconnect`; there was no schema rewrite or destructive
database operation. The core setup schema contains doctors, patients,
appointments, health metrics, suggestions, prescriptions, OTP storage, and
administrator credentials. ML predictions use a separate migration. A previous
read-only metadata check observed 9 tables and 11 foreign-key references. No
patient rows or credentials were read in that check.

`.env` is Git-ignored. `database/`, `docs/`, `ml/`, `python-rag/`, `dataset/`,
and `exports/` have Apache access restrictions. Existing service tokens remain
separate: `DEVICE_INGEST_TOKEN`, `ML_API_TOKEN`, and `RAG_SERVICE_TOKEN`.

## Findings and current priority

| Severity | Finding / status |
|---|---|
| High / deployment | Authentication, PHI access, valid CRUD, and service-to-database workflows still need end-to-end testing with dedicated accounts and a disposable database. No destructive or PHI-backed test was run. |
| Medium / deployment | Browser-rendered desktop/tablet/mobile, keyboard walkthrough, and console/network checks have not been verified in an authenticated browser. CSS/source checks are not visual verification. |
| Medium / deployment | Local HTTP is available in XAMPP; use HTTPS and production secret/retention controls before handling real PHI. |
| Low / fixed | Patient/doctor registration now validates before opening PDO; shared PDO honors bounded `DB_CONNECT_TIMEOUT`; stale Python executable documentation was removed. |
| Low / known | Upstream `httpx2` deprecation warning remains in one RAG test; tests pass. |

Administrator implementations were grouped under `admin/` and `admin/actions/`
with root compatibility shims; direct HTTP access to the implementation folder
is denied. Broader route moves remain deferred until each route has a tested
compatibility mapping and an isolated integration database is available. No
schema changes were made. See the connection and migration maps for current
verification.

## Connection audit update (2026-10-27)

- The current source inventory is 43 PHP files and 16 application Python files
  (excluding virtual environments/caches). The verifier resolves 71 static
  PHP include targets, 174 local HTML/PHP/HTM browser references, and one
  literal JavaScript fetch route.
- Apache served the legacy admin login page and stylesheet with HTTP 200; the
  unauthenticated dashboard/login handlers redirected with HTTP 302. Direct
  requests to `config/`, `includes/`, and implementation files in `admin/`
  returned HTTP 403.
- The local PDO connection returned `1` for a read-only `SELECT 1`. The Python
  ML and RAG unit suites passed (5 and 32 tests respectively; two optional RAG
  tests skipped). The local ML/RAG services were not running at the final
  health check.
- See [CONNECTION_AUDIT.md](CONNECTION_AUDIT.md),
  [FILE_MIGRATION_MAP.md](FILE_MIGRATION_MAP.md), and
  [TEST_REPORT.md](TEST_REPORT.md) for full evidence and limitations.

## Source inventory

The organization audit recorded 128 tracked paths at its checkpoint; the later
full audit recorded 131 tracked paths. It inventoried 38 PHP, 8 HTML, 6 CSS,
7 JavaScript, 23 Python, and 3 SQL files at the respective audit snapshots.
Generated/binary artifacts and ignored private configuration were path-checked
but not line-reviewed. The only explicitly unreviewed tracked source is the
unreferenced classroom exercise documented in the full audit.

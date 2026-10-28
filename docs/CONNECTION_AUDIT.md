# MedConnect Connection Audit

Audit date: 2026-10-27  
Project root: `C:\xampp\htdocs\medconnect`

This audit records the connections found by source inspection. Files under `.venv`, Python caches, generated vector indexes, and ignored `.env` files are runtime/generated/private artifacts and are not treated as application source. Secret values were not opened or copied into this report.

## Current structure and entry points

The application retains root-level compatibility routes because browser links, forms, device firmware, and existing bookmarks call them directly. Administrator implementations now live under `admin/` and `admin/actions/`; root-level shims preserve the existing URLs. Other features remain in the existing `doctor`, `patient`, `includes`, `css`, `js`, `database`, `ml`, `python-rag`, and `docs` folders. There is no existing `auth/` or `assets/` directory, so neither was added just to match a template. This change adds `config/database.php` as the canonical PDO constructor and `scripts/verify-project.ps1` as the project verifier.

Web entry points are `index.html`, `choose-role.html`, the role login/registration HTML pages, and the role dashboards (`admin-dashboard.php`, `doctor-dashboard.php`, `patient-dashboard.php`). Root-level PHP files also implement authentication handlers, profile updates, appointments, health ingestion, exports, downloads, predictions, and assistant API requests. Python services are `ml/api.py` and `python-rag/app.py`.

## Database connection inventory

| Source | Current reference / method | Target | Exists | Action / status |
|---|---|---|---|---|
| PHP routes listed below | `require_once __DIR__ . '/includes/db_connection.php'` | `includes/db_connection.php` compatibility shim → `config/database.php` | Yes | Kept route references stable; shim now loads the single canonical PDO constructor. |
| `doctor/login_doctor.php`, `doctor/register_doctor.php` | `require_once __DIR__ . '/../includes/db_connection.php'` | Same shim → canonical connection | Yes | Existing nested paths resolve. |
| `patient/login_patient.php`, `patient/register_patient.php` | `require_once __DIR__ . '/../includes/db_connection.php'` | Same shim → canonical connection | Yes | Existing nested paths resolve. |
| `python-rag/tests/php_python_contract.php` | Loads only `includes/env.php` | PHP environment loader | Yes | No DB connection needed by contract smoke test. |
| `ml/db.py` | Python MySQL driver and environment configuration | Existing MySQL database | Yes | Separate Python service access; configuration remains environment-backed. |

PHP database-consuming implementations load the compatibility shim through these locations: root routes `add-health.php`, `admin-reset.php`, `book_appointment.php`, `doctor-dashboard.php`, `download_prescription.php`, `export-data.php`, `forgot-password.php`, `gemini-chat.php`, `get_patient_metrics.php`, `patient-dashboard.php`, `patient-prediction.php`, `predict_health.php`, `save_data.php`, `update-doctor.php`, and `update-patient.php`; admin implementations `admin/dashboard.php`, `admin/login.php`, `admin/actions/admin-action.php`, and `admin/actions/delete-patient.php`; nested role handlers under `doctor/` and `patient/`. Root compatibility files `admin-action.php`, `admin-dashboard.php`, `admin-delete-patient.php`, and `admin-login.php` require those implementations and do not open a second connection.

Inspection found one PHP `new PDO` constructor and no PHP `mysqli_connect`/`new mysqli` implementation. The public variable contract remains `$conn` as PDO; existing query code uses PDO methods. `config/database.php` loads `includes/env.php`, reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_CONNECT_TIMEOUT`, uses `utf8mb4`, exception mode, native prepares, and a bounded connect timeout. On failure it logs SQLSTATE only and returns a generic HTTP 500 message. `includes/db_connection.php` is retained as a thin compatibility shim so existing URLs and page dependencies do not break.

## PHP filesystem include map

All listed literal include targets were resolved against their containing file. References remain valid; the database include is indirect through the shim described above.

| Source group | Current reference | Resolved target | Exists | Change |
|---|---|---|---|---|
| Root PHP pages and handlers | `__DIR__ . '/includes/<file>.php'` | `includes/<file>.php` | Yes for the referenced session, db, auth, assistant, ML, SMS and response helpers | Stable root-relative filesystem references retained. |
| `admin/dashboard.php`, `admin/login.php` | `__DIR__ . '/../includes/<file>.php'` | Root `includes/<file>.php` | Yes | Moved implementations; legacy root wrappers preserve browser endpoints. |
| `admin/actions/*.php` | `dirname(__DIR__, 2) . '/includes/<file>.php'` | Root shared helpers | Yes | Moved handlers; existing POST endpoint names remain as wrappers. |
| `doctor/login_doctor.php`, `doctor/register_doctor.php` | `__DIR__ . '/../includes/<file>.php'` | Root `includes/<file>.php` | Yes | No change. |
| `patient/login_patient.php`, `patient/register_patient.php` | `__DIR__ . '/../includes/<file>.php'` | Root `includes/<file>.php` | Yes | No change. |
| `python-rag/tests/php_python_contract.php` | `dirname(__DIR__, 2) . '/includes/env.php'` | Root `includes/env.php` | Yes | No change. |
| `python-rag/tests/test_doctor_access.php` | `dirname(__DIR__, 2) . '/includes/assistant_access.php'` and `assistant_response.php` | Root assistant helpers | Yes | No change. |
| `config/database.php` | `__DIR__ . '/../includes/env.php'` | Root `includes/env.php` | Yes | Added canonical config reference; verified. |
| `includes/db_connection.php` | `__DIR__ . '/../config/database.php'` | `config/database.php` | Yes | Replaced former constructor with compatibility shim; verified. |

The verifier script checks static `__DIR__` and `dirname(__DIR__, N)` include expressions for target existence. In the last run, all 71 literal include references resolved. Dynamic PHP includes are not rewritten automatically; they require manual review.

## Browser URL, form, asset and endpoint connections

| Caller | Reference | Target / service | Status |
|---|---|---|---|
| Root HTML/PHP pages | Root-relative page names such as `patient-login.html`, `doctor-login.html`, `admin-login.html`, `*-dashboard.php`, `logout.php`, `forgot-password.php` | Existing root routes | Preserved; no route relocation performed. |
| Root dashboards and pages | `css/*.css`, `js/*.js`, `app-icon.svg` | Root `css/`, `js/`, and icon | Paths are intended to resolve from the current root-level routes; static validation is performed by the verifier for literal local asset paths. |
| `patient-dashboard.php` | `book_appointment.php`, `download_prescription.php`, `add-health.php`, `patient-prediction.php` | Existing root PHP routes | Existing files verified. |
| `doctor-dashboard.php` | `gemini-chat.php`, `get_patient_metrics.php`, `download_prescription.php` and profile/appointment POST handlers | Existing root PHP routes | Existing files verified. |
| `admin-dashboard.php`, `admin-action.php`, `admin-delete-patient.php`, `admin-login.php` | Existing browser endpoints | Root shims include implementations in `admin/` | Legacy URLs remain; Apache returns 403 for direct requests to implementation files. |
| `gemini-chat.php` | `RAG_URL` (default loopback `/chat`), `X-MedConnect-Token` header | FastAPI `python-rag/app.py` `/chat` | Contract matches the Python route; service token is server-side. |
| `gemini-chat.php` | `RAG_SERVICE_TOKEN` | Python service-token configuration | Environment name matches `.env.example` and Python config. |
| `includes/ml_client.php`, dashboards | `ML_API_URL` (default `127.0.0.1:8001`), `/predict/patient/{id}`, `/health`, `X-MedConnect-Token` | `ml/api.py` | Endpoint names and token header align; service availability requires runtime check. |
| `save_data.php` | POST `X-MedConnect-Device-Token`; `DEVICE_PATIENT_ID` | Sensor ingestion route | Existing handler verifies configured token and patient target before database insertion; live device request not sent during this audit. |
| HTML dashboard pages | CDN Bootstrap, Bootstrap Icons, Chart.js and Google Fonts URLs | Third-party static hosts | Externally hosted references; require network availability and are not vendored. |

## Python imports, model and document paths

| Source | Connection | Destination | Status |
|---|---|---|---|
| `ml/api.py` | Uvicorn app import | `ml/api.py:app` | Local module; launched with `--app-dir ml`. |
| `ml/api.py` | imports `db.py`, preprocessing/prediction modules | `ml/db.py`, `ml/predict.py`, `ml/preprocess.py` | Local source modules; compile/import checks are run separately. |
| `ml/predict.py`, `ml/train_model.py`, `ml/evaluate_model.py` | `Path(__file__)`/module-local paths | `ml/models`, `ml/data`, reports | Relative to source; model artifacts are checked without modifying them. |
| `python-rag/app.py` | imports local configuration, schemas, routing, retrieval, Gemini | `python-rag/*.py` | Local module; launched with `--app-dir python-rag`. |
| `python-rag/ingest.py` and retrieval/config modules | `Path(__file__)`-based document and index paths | `python-rag/documents`, `python-rag/data` | Source-relative; generated index remains out of public links. |
| Root `run-rag.cmd` | Uvicorn command | `python-rag/app.py` | Existing launcher; environment/dependency availability remains a runtime prerequisite. |

No Python module was moved. The audit intentionally excludes `.venv` package sources and caches; dependency manifests and application modules are the relevant reviewed connection points.

## Findings and remediation

| Severity | Finding | Root cause | Remediation / state |
|---|---|---|---|
| Medium | PHP DB constructor was under `includes/` despite the requested canonical `config/database.php` location. | Initial project predates the proposed folder layout; all pages depend on the existing include path. | Canonical constructor is now `config/database.php`; `includes/db_connection.php` remains a compatibility shim. `$conn` PDO API and routes stay unchanged. |
| Medium | Administrator dashboard, login handler, and management handlers were root-level despite the role folder. | Legacy forms and navigation submit to root filenames. | Implementations now live under `admin/`; root compatibility shims preserve route names. Apache blocks direct requests to implementation files, which are loaded by the shims. |
| Medium | New configuration and include directories did not have direct HTTP denial rules. | The database config location did not exist in the previous layout, and shared PHP helpers are not browser routes. | Added `config/.htaccess` and `includes/.htaccess`; Apache returned HTTP 403 for direct requests to `config/database.php` and `includes/env.php`. |
| Low | Admin implementation files contained paths relative to the project root, while their new filesystem location is nested. | Shared helpers and ML artifacts live outside `admin/`. | Updated the four moved implementations to parent-root includes/data paths; the verifier resolves them and legacy root wrappers return expected redirects. |
| Low | There was no repository-level automated checker for static PHP include references and local config assumptions. | Validation was performed as ad hoc checks. | Added `scripts/verify-project.ps1`; it uses available PHP/Python tools, checks static includes and required files, and reports service checks as unavailable when not running. |
| Informational | Root-level entry routes coexist with feature folders. | Existing browser links, redirects, sensor API and login forms target root-level files. | Deliberately preserved; moving them would require compatibility redirects and broad route updates, with no functional benefit for this task. |
| Informational | `ML_API_TOKEN` and `RAG_SERVICE_TOKEN` are distinct names and have separate consumers. | Two independent local services. | No token consolidation; verifier checks names without reading secret values. |

## Verification boundaries

The static verifier does not claim that a browser-rendered page is visually correct or that Gemini or an ESP32 device is reachable. During this audit Apache and MySQL were reachable, and the web routes returned expected responses. The RAG and ML health endpoints were checked with `-CheckLocalServices` and were not running at that time. No schema changes or database writes were made for this connection audit.

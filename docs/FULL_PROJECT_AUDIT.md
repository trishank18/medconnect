# MedConnect Full Project Audit

Audit date: 2026-10-27  
Project root: `C:\xampp\htdocs\medconnect`  
Scope: application source, PHP/UI routes, database definitions and access paths, ML and RAG services, configuration, local HTTP behavior, and available automated tests.

## Executive summary

The project is a PHP/MySQL application with separate patient, doctor, and administrator routes, a local Python analytics service, and a separate FastAPI RAG service. The existing dashboard design system is shared across the role dashboards, authentication, public, and utility pages. Root routes and existing schema were retained.

This audit did not modify the database or use real patient information. A verified Git bundle was created before the preceding organization pass; its path and hash verification are recorded in [project-organization-audit.md](project-organization-audit.md). The current pass made four focused code/documentation edits. The schema and application records were not changed.

During the first registration probe, MySQL was stopped and the handler returned the database-unavailable message before validation. The XAMPP MySQL process became available later in the audit: the shared PHP connection now passes a constant read-only query, and read-only metadata confirms the existing schema has 9 tables and 11 foreign keys. The patient and doctor CSRF token flows work. Valid registration and CRUD were not run against the regular `medconnect` database; no disposable database was configured.

## Inventory and review coverage

- 131 tracked repository paths were present at the time of this audit.
- The project checklist contains 105 application-file entries; it marks the unreferenced `patient/Python_Module2_2025/Python_Module2_2025.py` classroom exercise as not reviewed because it contains intentional syntax examples and is not imported by the app. The remaining entries have recorded review status and inspected line ranges in `AUDIT_CHECKLIST.md`.
- The tracked application includes 38 PHP files, 8 HTML files, 6 CSS files, 7 JavaScript files, 23 Python files, 3 SQL files, 5 saved `.joblib` model artifacts, and project documentation/configuration/assets.
- Excluded from manual line review: `.env` and ignored local secrets; virtual environments and caches; binary model files; generated ML reports; the local generated RAG index/chunk files; uploaded/exported or dataset contents; and images/binary assets. Paths and access rules were checked without printing private values or record contents.
- PHP syntax, Python compile, Python tests, JavaScript syntax, API/security tests, local HTTP status checks, and prior read-only database metadata checks provide execution evidence, but do not establish full production or clinical readiness.

## Architecture and dependency map

```text
Browser (patient / doctor / administrator)
  ├─ HTML pages and role dashboards
  ├─ js/dashboard-shell.js, js/theme-init.js, js/design-switch.js
  ├─ js/form-csrf.js ──> csrf-token.php ──> includes/session.php
  └─ js/health-assistant.js ──> gemini-chat.php
                               ├─ includes/session.php / role and CSRF checks
                               ├─ includes/assistant_access.php
                               ├─ includes/db_connection.php ──> MySQL medconnect
                               └─ loopback HTTP + RAG_SERVICE_TOKEN ──> python-rag/app.py
                                                                       ├─ routing.py
                                                                       ├─ retrieval.py / chunking.py / FAISS
                                                                       └─ gemini_service.py ──> Google Gemini

ESP32/device ── DEVICE_INGEST_TOKEN ──> save_data.php ──> health_metrics
Patient/doctor UI ──> PHP session-scoped handlers ──> includes/db_connection.php ──> MySQL
Patient analytics UI ──> predict_health.php / includes/ml_client.php
                       ── ML_API_TOKEN ──> ml/api.py ──> ml/db.py + saved models
Password recovery ──> forgot-password.php / includes/sms_service.php ──> Twilio
```

The browser does not receive service tokens or call the Python service directly. Patient readings are served by parameterized PHP/MySQL queries scoped to the authenticated patient. Doctor readings require an approved doctor and an authorized assignment/appointment relationship. Patient data is not indexed into the global RAG corpus or sent in RAG prompts. RAG handles approved MedConnect documents and general questions; private data requests are handled or rejected at the PHP authorization boundary.

Key entry points and service files:

| Area | Entry points and dependencies |
| --- | --- |
| Public and authentication | `index.html`, `choose-role.html`, role login/register HTML, `csrf-token.php`, `patient/login_patient.php`, `doctor/login_doctor.php`, `admin-login.php` |
| Patient | `patient-dashboard.php`, `add-health.php`, `book_appointment.php`, `get_patient_metrics.php`, `patient-prediction.php`, `update-patient.php` |
| Doctor | `doctor-dashboard.php`, `doctor/register_doctor.php`, `update-doctor.php`, `download_prescription.php` |
| Administrator | `admin-dashboard.php`, `admin-action.php`, `admin-delete-patient.php`, `export-data.php`, `admin-reset.php` |
| Shared PHP | `includes/session.php`, `includes/db_connection.php`, `includes/env.php`, authentication, ML, assistant, and SMS helpers |
| Device | `save_data.php` with `DEVICE_INGEST_TOKEN` and explicit device/patient configuration |
| ML | `ml/api.py`, `ml/db.py`, `ml/preprocess.py`, `ml/predict_health.py`, saved models under `ml/models/` |
| RAG | `python-rag/app.py`, `routing.py`, `schemas.py`, `chunking.py`, `ingest.py`, `retrieval.py`, `gemini_service.py` |
| Shared UI | `css/theme.css`, `css/app-ui.css`, `css/style.css`, `css/dashboard-modern.css`; `js/dashboard-shell.js`, `js/theme-init.js`, `js/design-switch.js`, and page scripts |

## Findings, causes, and changes

### Confirmed issues fixed in this pass

| Severity | Finding and root cause | Change |
| --- | --- | --- |
| Medium | Patient and doctor registration handlers opened PDO before validating submitted fields. During the observed MySQL outage, even malformed test submissions returned the database-unavailable response instead of a field-validation response. | Moved the shared DB include behind CSRF and input validation in `patient/register_patient.php` and `doctor/register_doctor.php`. Valid inserts still use the same prepared statements, password hashing, tables, and verification workflow. |
| Low | `DB_CONNECT_TIMEOUT` was documented in `.env.example` and used by Python ML but ignored by PHP's shared PDO connection. | `includes/db_connection.php` now parses a bounded 1–30 second timeout (default 5) and passes it to PDO. Invalid values safely fall back to 5 seconds. |
| Low | The deployment paragraph in `README.md` instructed operators to configure `PYTHON_BIN`, but no code reads that variable. | Replaced the stale variable with the actual `ML_*` and `DEVICE_INGEST_TOKEN` configuration names. |

### Existing confirmed findings already addressed in the repository

The checked-in `AUDIT_CHECKLIST.md` and `docs/project-organization-audit.md` preserve the previous source-level findings and changes. Those include role/CSRF enforcement on state-changing routes, session role clearing and ID regeneration, prepared/scoped patient queries, doctor assignment validation, safer admin credential setup, protected PHI exports and private directories, output escaping, health-input bounds, sanitized service errors, model-output checks, and removal of an unvalidated numeric anomaly score/audio alert. These behaviors were source-reviewed and selectively tested; destructive or real-user operations were not invoked.

The existing shared dark/light dashboard design uses centralized tokens and shared responsive shell/components across role dashboards and the wider public/auth/content routes. UI styling was source-checked and static assets were served successfully. A headless browser capture attempt did not produce usable screenshots and no browser automation harness is configured, so pixel-level comparison, actual tablet/mobile rendering, and browser console inspection remain unverified.

## Database compatibility

- Canonical database name remains `medconnect`; the schema remains in `database/setup.sql`.
- The previous read-only inspection recorded 9 tables, their columns, primary/unique/index metadata, and 11 foreign-key references. The schema source and legacy migration contents were hash-checked against the Git checkpoint during the organization pass.
- `includes/db_connection.php` is the sole PHP PDO constructor found. PHP queries use prepared statements in the audited routes. ML uses its configured Python MySQL connector.
- No SQL migration was created or applied in this pass. No table, column, key, or record was added, dropped, reset, or changed.
- Current read-only checks pass: PHP PDO executes `SELECT 1`; `information_schema` reports 9 tables and 11 foreign keys in the configured schema. No patient rows or credentials were queried.
- A disposable database was not configured. Valid registration inserts, CRUD, transactions/rollback, and live authenticated authorization queries remain **BLOCKED** because they could change the regular `medconnect` database. Do not import `database/setup.sql` into an existing `medconnect` instance as a test; it selects that database. For integration tests, provision a separately named disposable database and adapt a test-only configuration first.

## UI and interaction status

- Dark-first teal/green MedConnect tokens, optional persisted light mode, shared sidebar, compact card/table/form styling, and responsive CSS are present in the existing shared design system.
- Patient, doctor, and admin dashboards retain their separate content and server routes. Authentication, registration, public, chatbot, monitoring, and utility pages retain their existing functions while using the shared style bridge.
- Patient and doctor registration forms obtain role-specific CSRF tokens from the same-origin endpoint. The browser uses `textContent` for assistant replies.
- HTTP smoke checks confirmed the public page, registration pages, and shared CSS load; private environment, database, and Python source paths are denied by Apache.
- **Not visually verified:** desktop/tablet/mobile screenshot comparison, keyboard/screen-reader walkthrough, chart resizing, and console/network inspection in a real browser.
- No feature was replaced by static sample data in this pass.

## Security and privacy

- Actual `.env` files were not read, printed, or modified. `.env.example` uses placeholders. `DEVICE_INGEST_TOKEN`, `ML_API_TOKEN`, and `RAG_SERVICE_TOKEN` are separate variables and their placeholders are not shared values; operators must generate distinct random values.
- Apache returned 403 for `.env`, database schema, and Python source checks. Export, docs, dataset, and RAG folders also have access-denial rules recorded in the organization audit.
- RAG API uses a server-to-server token; direct patient/doctor data questions are denied there. PHP resolves the identity from the session, does not trust a browser-supplied patient ID by itself, and rechecks doctor access in the data path.
- Gemini SDK errors are normalized into safe service errors. The configured default `gemini-3.8-flash` is a model identifier listed in Google's current Gemini API documentation; the live availability of a particular key/project was not tested. [Gemini API model catalog](https://ai.google.dev/gemini-api/docs/models), [Generate content API](https://ai.google.dev/api/generate-content).
- Remaining production risks: current session use is local HTTP unless HTTPS is configured (secure cookies activate under HTTPS); the reset throttling is session-scoped and can be bypassed with a fresh session; no third-party privacy/compliance review or clinical validation was performed. Do not deploy with real PHI until deployment controls, retention, consent, and legal requirements are reviewed.

## Tests and evidence

All results below are actual commands or requests executed in this workspace. No health-record writes were made.

| Check | Result | Evidence / limitation |
| --- | --- | --- |
| PHP syntax | **PASS** | `C:\xampp\php\php.exe -l` across all 38 PHP files, including after the registration edits. |
| JavaScript syntax | **PASS** | `node --check` on all 7 tracked `.js` files. |
| Python compilation | **PASS** | `compileall` for `ml/` and `python-rag/`, excluding virtual environments and caches. |
| ML tests | **PASS** | `ml/.venv/Scripts/python.exe -m pytest -q ml/tests`: 5 passed. |
| RAG tests | **PASS with skips** | `python-rag/.venv/Scripts/python.exe -m pytest -q python-rag/tests`: 32 passed, 2 optional/live tests skipped, 1 upstream `httpx2` deprecation warning. Gemini calls were mocked. |
| Python dependency consistency | **PASS** | `pip check` reported no broken requirements in both existing service virtual environments. |
| PHP assistant authorization helper | **PASS** | `C:\xampp\php\php.exe python-rag\tests\test_doctor_access.php` passed assignment/denial parameter-binding and mixed SpO₂ explanation checks. |
| Registration CSRF and malformed input | **PASS** | Local same-origin token fetch plus patient and doctor form POSTs returned expected 302 `invalid_input` redirects. No account was created; DB was not needed for these rejected requests. |
| Local Apache routes/protected paths | **PASS** | 7 HEAD checks: public/register/CSS routes returned 200; `.env`, schema, and RAG Python source returned 403. |
| MySQL read-only connection/schema | **PASS** | PHP PDO `SELECT 1` succeeded. Read-only `information_schema` counts returned 9 tables and 11 foreign keys. No record contents were queried. |
| Valid registration/CRUD/transactions | **BLOCKED** | No disposable database is configured. The regular `medconnect` database was not changed. |
| ML API health / model loading | **PASS** | Started FastAPI on a temporary loopback port with a throwaway token. `/health` returned `ok` and reported saved target artifacts. Response details were suppressed; no DB inference was invoked. The test process was stopped and the port closed. |
| ML API live DB inference | **BLOCKED** | MySQL is stopped. No patient query or inference was attempted. |
| RAG HTTP service / PHP transport | **PASS** | Started FastAPI on a temporary loopback port with a throwaway test token. `/health` returned `ok`; the PHP transport test received the expected 403 for a private-data request. No live records or Gemini request were involved. The test process was stopped and the port closed. |
| Live Gemini | **SKIPPED** | Would require an external API call/key and could incur usage. Mocked error/routing tests passed. |
| Visual browser testing | **NOT RUN** | Headless capture did not produce usable images and no browser automation harness is configured. Static routes/styles and syntax were checked. |
| `git diff --check` | **PASS** | No whitespace errors in current changes. |

The test suite includes mocked cases for MedConnect document routing, patient-data denial at the RAG boundary, general programming/SQL/ML questions, mixed SpO₂ explanations, empty retrieval, malformed input, missing/invalid Gemini configuration, retryable quota/network errors, prompt-injection treatment, chunking/ingestion/retrieval behavior, and PHP/Python payload contract shape.

## Files changed in this pass

- `patient/register_patient.php` — validate before connecting to MySQL.
- `doctor/register_doctor.php` — validate before connecting to MySQL.
- `includes/db_connection.php` — honor bounded `DB_CONNECT_TIMEOUT` from environment.
- `README.md` — remove stale `PYTHON_BIN` instruction and list the actual service variables.
- `docs/FULL_PROJECT_AUDIT.md` — this audit report.

The earlier organization pass and its path moves are listed in `docs/project-organization-audit.md`; that document also records the checkpoint bundle path and earlier test evidence.

## Windows/XAMPP startup commands

From PowerShell:

```powershell
Set-Location C:\xampp\htdocs\medconnect
Start-Process C:\xampp\apache_start.bat
Start-Process C:\xampp\mysql_start.bat
```

Start MySQL only when you intend to use the local `medconnect` database. Verify the XAMPP control panel shows Apache and MySQL running; do not import or reset the schema to troubleshoot a stopped service.

Start the Python RAG API in a separate PowerShell window after configuring the project-root `.env`:

```powershell
Set-Location C:\xampp\htdocs\medconnect
python-rag\.venv\Scripts\python.exe -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
```

Start the ML API in another PowerShell window. It reads DB settings from the project-root `.env`; `ML_API_TOKEN` must be available to both the ML process and Apache/PHP:

```powershell
Set-Location C:\xampp\htdocs\medconnect
$env:ML_API_TOKEN = '<same independently generated ML_API_TOKEN configured for Apache>'
ml\.venv\Scripts\python.exe -m uvicorn api:app --app-dir ml --host 127.0.0.1 --port 8001
```

Open `http://localhost/medconnect/`. Health checks are `http://127.0.0.1:8000/health` and `http://127.0.0.1:8001/health`. Do not put the RAG/ML/device tokens in browser JavaScript. Required environment names and placeholders are in the root `.env.example`; preserve the existing `.env` and use distinct secrets for `DEVICE_INGEST_TOKEN`, `ML_API_TOKEN`, and `RAG_SERVICE_TOKEN`.

## Remaining work and resolution steps

1. Provision a separately named disposable schema—not the user's regular `medconnect` database—to run registration, CRUD, transaction, and role-access integration tests. The current XAMPP MySQL process accepts read-only checks.
2. Set valid server-only Gemini/RAG/ML/device configuration. Run the optional live Gemini test only when external API use is intended; test live PHP-to-RAG and PHP-to-ML transport on loopback.
3. Use a browser to inspect desktop, tablet, and mobile layouts and check console/network errors. Test role switching with dedicated non-PHI accounts.
4. Confirm the source links and connection settings in Apache's actual process environment. Restart Apache after environment changes.
5. The existing unreferenced classroom exercise remains the only explicitly unreviewed tracked source file. It is not part of the running application and contains intentional malformed examples; review it separately only if the educational file itself is in scope.

**Final status:** source review is documented and broad syntax/unit/integration-boundary checks passed. Registration validation and the local HTTP surface were verified. Valid MySQL workflows, live service integration, and browser-rendered UI remain blocked/not run; this audit does not certify production readiness.

## Administrator UI update — 2026-10-27

- Added `css/admin-dashboard.css`, loaded after the shared dashboard/theme styles from `admin-dashboard.php`. It refines the administrator workspace header, metric/action card sizing, approval queue rows, focus states, and tablet/mobile spacing using the existing MedConnect CSS tokens.
- Preserved existing administrator queries, approval/rejection forms, CSRF fields, CSV export routes, bulk patient assignment operation, and patient deletion workflow. No schema or authentication changes were made.
- `C:\xampp\php\php.exe -l admin-dashboard.php` passed. `git diff --check` passed (Git reported only its LF/CRLF normalization warning).
- HTTP checks with `curl.exe` returned `200 OK` for the new stylesheet and `302 Found` from the protected dashboard to `admin-login.html`. PowerShell `Invoke-WebRequest` itself failed in the local shell. No authenticated browser session was used, so rendered visual/viewport verification is still unverified; admin actions were not submitted.

## Repository re-verification — 2026-10-27

- The saved organization checkpoint bundle exists at the path recorded in `docs/project-organization-audit.md`. Current recursive layout still has root-level admin entry points plus distinct `doctor/`, `patient/`, `includes/`, `css/`, `js/`, `database/`, `docs/`, `tests/`, `ml/`, and `python-rag/` modules. Legacy setup paths have no live source references.
- Recursive PHP connection scan still finds a single PDO constructor at `includes/db_connection.php`; no MySQLi or legacy MySQL connection constructor was found. Local `.env` paths are ignored by Git and their contents were not inspected.
- Re-run results: PHP lint **38 passed**; JavaScript syntax **7 passed**; Python source compile **22 passed** (virtualenvs/caches excluded); ML **5 passed**; RAG **32 passed, 2 skipped**, with one upstream `httpx2` deprecation warning; both service environments' `pip check` passed. A full `compileall` attempt was stopped because it traversed bundled virtualenv files; the bounded source-only compile then passed.
- `curl.exe -I` returned `200` for `css/admin-dashboard.css`, `302` for unauthenticated `admin-dashboard.php` to `admin-login.html`, and `403` for `database/setup.sql`. `git diff --check` passed with a Windows line-ending normalization warning only. No database writes or schema changes were made.
- Browser-rendered desktop/tablet/mobile checks and authenticated CRUD/access workflows remain unverified; no new claim of visual or end-to-end verification is made.

## Administrator authentication UI — 2026-10-27

- `admin-login.html` and `admin-reset.php` now share the dashboard's dark background, green accent, card borders, typography, form fields, and responsive layout through `css/admin-auth.css`.
- Added explicit form labels, dark success/error feedback, and accessible show/hide password controls in `js/admin-auth.js`. Login action, reset handler, CSRF flows, field names, password verification/hashing, and server validation remain unchanged.
- Verification: `admin-reset.php` PHP lint passed; `js/admin-auth.js` passed `node --check`; Apache returned 200 for the admin login HTML, auth stylesheet, and auth JavaScript. The `admin_login` CSRF endpoint returned a token of the expected shape; its value was withheld. `git diff --check` passed with only Windows line-ending notices. Login/reset were not submitted and no authenticated visual browser test was performed.

## Connection and layout update — 2026-10-27

The later connection/layout pass supersedes earlier statements about the current
admin implementation paths and PDO constructor location. Implementations now
live in `admin/dashboard.php`, `admin/login.php`, and `admin/actions/`; the old
root admin URLs are small compatibility shims. `config/database.php` is the
only PHP PDO constructor, and `includes/db_connection.php` now forwards to it.
The compatibility routes continue to load the shared helpers while Apache
returns 403 for direct requests to internal `admin/`, `config/`, and `includes/`
files. Current evidence is recorded in `docs/CONNECTION_AUDIT.md` and
`docs/TEST_REPORT.md`.

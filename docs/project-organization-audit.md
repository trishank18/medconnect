# MedConnect Project Organization Audit

Audit date: 2026-10-27  
Repository: `main` at `1d5c5fe` before organization changes  
Checkpoint: verified Git bundle `C:\Users\trish\AppData\Local\Temp\medconnect-audit-20261027-115647.bundle`

## Inventory and review scope

The starting repository contained 128 tracked paths. The source, configuration,
and documentation inventory contained 118 text/config paths:

| Type | Count |
| --- | ---: |
| PHP | 38 |
| HTML | 8 |
| CSS | 6 |
| JavaScript | 7 |
| Python | 23 |
| SQL | 3 |
| Markdown | 6 |
| Text | 3 |
| JSON | 9 |
| Environment templates | 3 |
| Other configuration, scripts, and web assets | 12 |

The existing [`AUDIT_CHECKLIST.md`](../AUDIT_CHECKLIST.md) records line ranges,
review states, findings, and checks for the application source files audited in
the preceding implementation work. This organization pass reconciled the
tracked inventory, references, entry points, connection constructors, access
rules, and on-disk folders before moving the legacy setup materials.

Excluded from manual source review: `.env` files and other ignored secrets,
virtual environments, Python bytecode, the local FAISS index and chunk store,
the ignored local RAG corpus, exported CSVs, saved `.joblib` model binaries,
generated model reports, and the dataset CSV contents. Their paths and access
controls were inspected without printing secret values or patient records.
The tracked classroom exercise `patient/Python_Module2_2025/Python_Module2_2025.py`
is not imported by the application and remains in place. The empty
`package-lock.json` has no matching `package.json`; it was retained to avoid
removing a tracked file before the frontend dependency history is confirmed.

## Existing modules and features

- Public landing, role selection, patient/doctor/admin sign-in, patient and
  doctor registration, password reset, and admin credential initialization.
- Patient dashboard, profile updates, health history, manual reading entry,
  appointment booking, prescriptions, doctor details, and health assistant.
- Doctor dashboard, assigned/appointment-related patient views, authorized
  vitals, appointments, prescriptions, and patient suggestions.
- Administrator dashboard, approval and account management, scheduling,
  patient deletion, and controlled data exports.
- Device-token-protected health measurement ingestion and session-scoped
  patient/doctor record reads.
- Optional Random Forest inference API, prediction persistence, and patient
  prediction page. Training and inference code and saved models are kept under
  `ml/`.
- FastAPI RAG service, document ingestion, chunking, retrieval, Gemini
  generation, and tests under `python-rag/`.
- Shared PHP helpers in `includes/`, styles in `css/`, and browser behavior in
  `js/`; the PWA manifest and service worker remain at the web root.

## Database and dependency findings

- The application has one PHP PDO connection implementation at
  `includes/db_connection.php`. A recursive PHP scan found no competing PDO,
  MySQLi, or legacy `mysql_*` connection constructor. Application queries
  require that shared connection file.
- The schema source uses the existing `medconnect` database and existing
  doctors, patients, health metrics, appointments, suggestions, prescriptions,
  password-reset OTP, and administrator credential tables. The ML service has
  a separate versioned migration for prediction records. No database command
  was run as part of this file reorganization.
- Python dependencies are declared separately for the ML service and RAG
  service; root `requirements.txt` is a deployment aggregate. There is no
  Composer manifest and no `package.json`; no new package manager was added.
- Doctor and patient endpoints are used directly by existing form actions,
  redirects, browser requests, and device code. They remain at their current
  URLs. Existing role folders, `includes/`, `css/`, `js/`, `ml/`, and
  `python-rag/` are already meaningful modules, so they were not duplicated or
  moved.

## Organization changes

| Previous path | New path | Change |
| --- | --- | --- |
| `read me .txt/sql.txt` | `database/setup.sql` | Moved initial schema and local-only seed setup. |
| `read me .txt/migrate-verification.sql` | `database/migrations/legacy-add-verification-status.sql` | Moved legacy migration; documented that it only applies if the column is absent. |
| `read me .txt/instruction.txt` | `docs/setup/xampp.md` | Moved local setup notes. |
| `read me .txt/iot_esp32_MAX30102_K12/iot_esp32_MAX30102_K12.txt` | `docs/device/esp32-max30102.ino` | Moved firmware example and replaced personal network values with placeholders. |
| `read me .txt/.htaccess` | `docs/.htaccess` | Preserved direct web-access denial for documentation. |
| New | `database/.htaccess` | Denies direct web access to schema and migrations. |
| New | `tests/requirements.txt` | Declares pytest as a development-only test dependency, separate from production service requirements. |

README, architecture links, and the existing audit checklist were updated. No
PHP routes, database schema, records, authentication flows, ML models, RAG
indexes, or API payloads were moved or rewritten.

The ML API now loads database and service settings from the project-root `.env`
through `python-dotenv`, while existing process environment variables still
take precedence. This removes its separate duplicate DB credential template;
the PHP PDO connection and both Python services use the same root settings.
The dependency is declared in `ml/requirements.txt`, and the ML setup guide was
updated to use the shared configuration.

`pytest` is declared in `tests/requirements.txt` rather than either runtime
requirements file. `.gitignore` excludes test caches, and `.dockerignore` no
longer references the retired legacy folder; it excludes the moved docs and
database setup files from the application image.

## Reference and path audit

Before the moves, a repository-wide search found references to the legacy setup
directory only in `README.md`, `ARCHITECTURE.md`, `AUDIT_CHECKLIST.md`, and the
local setup note. Those references were updated. The root PHP endpoints and
their browser-facing relative asset paths were intentionally preserved. The
PHP connection scan found only `includes/db_connection.php` constructs PDO.
The device sample no longer contains the developer's local Wi-Fi name or a
fixed LAN address.

## Verification status

PHP/Python test commands, HTTP access checks for the new protected directories,
the stale-path search, and Git whitespace check are listed below.

### Results

- PHP syntax: **PASS**, `php -l` on all 38 tracked PHP files.
- JavaScript syntax: **PASS**, `node --check` on all 7 tracked JavaScript files.
- Python compile: **PASS**, compileall on `ml/` and `python-rag/`.
- ML suite: **PASS**, 5 tests passed. FastAPI emitted an upstream Starlette/httpx
  deprecation warning; there were no failures.
- RAG suite: **PASS**, 34 tests ran: 32 passed and 2 optional live tests were
  skipped (Gemini credentials and the separate live contract test are opt-in).
  The run reported one upstream `httpx2` request-API deprecation warning.
- Local API startup: **PASS**, RAG `/health` returned 200. ML `/health` returned
  `ok` and loaded the five saved target models.
- PHP assistant checks and PHP-to-Python protected-request contract: **PASS**.
- MySQL metadata: **PASS**, existing tables, columns, primary/unique/index
  definitions, and 11 foreign-key references inspected read-only; no rows or
  schema were changed. Import/migration was **NOT RUN**: the setup script
  explicitly selects `medconnect`, and no separate disposable database was
  configured for this pass.
- Apache: **PASS**, home and CSS returned 200; moved database/docs files returned
  403; the old schema URL returned 404.
- Stale legacy path/network-value scan: **PASS**. `git diff --check`: **PASS**;
  Git only reported expected Windows line-ending normalization notices.
- Audit checklist integrity: **PASS**, all 105 file-path entries resolve in the
  reorganized working tree.
- Content hashes for the moved setup schema, legacy migration, and docs access
  rule match their pre-move Git blobs; the SQL itself was not rewritten.

Not run: live Gemini, actual patient/doctor account workflows, record-changing
CRUD operations, sensor posting, model inference against a real patient's rows,
or device hardware tests. They require external credentials, authenticated
accounts, real/personal data, a configured disposable database, or physical
hardware. No such data or external service was used by these checks.

### Re-verification — 2026-10-27

After the administrator UI update, the project still has the route-preserving
organization described above, with administrator entry points at the web root
and role/service modules in their existing directories. The checkpoint bundle remains
available at the recorded path. A recursive connection scan still finds only
`includes/db_connection.php` constructing PDO; `.env` files remain ignored and
their contents were not read. PHP lint passed for 38 files, JavaScript syntax
passed for 7 files, source-only Python compilation passed for 22 files, ML tests
passed 5/5, and RAG tests passed 32 with 2 optional skips and one upstream
deprecation warning. Both Python environments passed `pip check`. HTTP checks
returned 200 for the admin stylesheet, 302 for the protected admin dashboard,
and 403 for the database setup SQL. No DB writes/schema changes occurred.
Authenticated browser rendering and CRUD/access flows remain not run.

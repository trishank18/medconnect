# MedConnect Test Report

Latest recorded verification: 2026-10-28. Full coverage and limitations are in
[FULL_PROJECT_AUDIT.md](FULL_PROJECT_AUDIT.md). These results are only checks
actually executed in this workspace; they do not certify production readiness.

## Role session fix verification (2026-10-28)

| Check | Command / evidence | Result |
|---|---|---|
| PHP syntax after auth changes | `C:\xampp\php\php.exe -l` across all project PHP files | PASS: 45 files. |
| Role session isolation | `C:\xampp\php\php.exe tests\php\session_roles.php` | PASS: synthetic role login, legacy flat-key migration, independent logout, and identity preservation. |
| Unauthenticated dashboard redirects | `curl.exe` against `doctor-dashboard.php`, `patient-dashboard.php`, and `admin-dashboard.php` | PASS: HTTP 302 to the matching doctor, patient, or admin login page. |
| Login pages | `curl.exe` against doctor, patient, and admin login HTML | PASS: all returned HTTP 200. |
| Role idle expiry | Included in `tests\php\session_roles.php` | PASS: an idle doctor expires at 90 minutes while patient activity remains valid; patient expiry also clears only the patient auth state. |
| Real credential checks / registrations | Not submitted to the existing `medconnect` database | NOT RUN: no disposable account set/schema was prepared; no user data was modified. |
| Browser role-switch/logout sequence | Not exercised in a browser with valid test accounts | NOT RUN: complete with disposable test accounts using [SESSION_AUTH_FIX.md](SESSION_AUTH_FIX.md). |

The connection/configuration changes made on 2026-10-27 were separately
validated with `scripts/verify-project.ps1`: 43 PHP files linted, 71 static
PHP include references resolved, 174 local browser links/assets resolved, one
literal JS fetch endpoint resolved, 22 Python application files compiled, and
five model artifacts were found. Result: 0 failures, 0 warnings. The script
does not test dynamic URL construction, visual browser layout, or external
service correctness.

## Passed

| Check | Command / evidence | Result |
|---|---|---|
| PHP syntax | `C:\xampp\php\php.exe -l` across repository PHP files | 43 files passed in the current verifier run, including moved admin implementations and root compatibility shims. |
| JavaScript syntax | `node --check` on tracked JS files; separately `node --check js/admin-auth.js` | 7 existing files and new admin auth script passed. |
| Python source compilation | Source-only compile excluding `.venv` and caches | 22 Python source files compiled. |
| ML unit tests | `ml\.venv\Scripts\python.exe -m pytest ml\tests -q` | 5 passed. |
| RAG unit tests | `python-rag\.venv\Scripts\python.exe -m pytest python-rag\tests -q` | 32 passed, 2 optional tests skipped; one upstream `httpx2` deprecation warning. Gemini tests use mocks. |
| Python dependency checks | `-m pip check` in both existing service environments | Both reported no broken requirements. |
| Admin auth asset delivery | `curl.exe -I` login HTML, `css/admin-auth.css`, `js/admin-auth.js` | HTTP 200. |
| Admin dashboard protection | `curl.exe -I` protected dashboard | HTTP 302 to admin login without an admin session. |
| Admin login CSRF endpoint | Same-origin loopback request; token value withheld | HTTP 200 and valid token field shape. |
| Private schema HTTP access | `curl.exe -I database/setup.sql` | HTTP 403. |
| Working-tree whitespace | `git diff --check` | Passed; only Git's Windows line-ending notices were emitted. |
| Project connection verifier | `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-project.ps1` | 43 PHP lint checks, 71 static include targets, 174 browser references (including `hash.htm` assets/links), 1 JavaScript fetch target, required config files and environment-variable names passed; 22 Python modules compiled; five model artifacts detected; 0 failures and 0 warnings. |
| Hash page behavior | `node --check js/text-hash.js`; temporary Node VM DOM smoke test; Apache checks | JavaScript syntax passed; SHA-256 of `abc` matched the known digest, copy and clear controls passed; `hash.html`, compatibility redirect `hash.htm`, CSS, and JS returned HTTP 200. |
| Canonical PDO include path | PHP CLI requiring `config/database.php` then `SELECT 1` | Passed; read-only query returned `1`. No data was changed. |
| Local Apache route and private-path check | `curl.exe` status checks for root, admin login, CSS, protected admin dashboard, config PHP and shared env helper | Root/admin login/CSS returned 200; protected dashboard returned 302; direct config/helper requests returned 403. |
| Admin compatibility routes | `curl.exe` status checks for admin dashboard/login and nested implementation paths | Legacy dashboard/login endpoints returned 302 when unauthenticated; nested `admin/dashboard.php` and `admin/actions/admin-action.php` returned 403 as intended. |
| Optional local service health | `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-project.ps1 -CheckLocalServices` | Website and MySQL remained available; RAG `127.0.0.1:8000/health` and ML `127.0.0.1:8001/health` were not running. The script reported 2 warnings and 0 failures. |
| Current Python unit tests | `ml\.venv\Scripts\python.exe -m pytest ml\tests -q`; `python-rag\.venv\Scripts\python.exe -m pytest python-rag\tests -q` | ML: 5 passed. RAG: 32 passed, 2 skipped; one upstream `httpx2` deprecation warning. |
| Current dependency checks | `-m pip check` in ML and RAG virtual environments | Both: no broken requirements. |

## Reproduction commands (PowerShell from project root)

```powershell
$php = 'C:\xampp\php\php.exe'
$phpFiles = rg --files -g '*.php'
$phpFiles | ForEach-Object { & $php -l $_ }

$jsFiles = rg --files -g '*.js'
$jsFiles | ForEach-Object { node --check $_ }

& 'ml\.venv\Scripts\python.exe' -m pytest ml\tests -q
& 'python-rag\.venv\Scripts\python.exe' -m pytest python-rag\tests -q
& 'ml\.venv\Scripts\python.exe' -m pip check
& 'python-rag\.venv\Scripts\python.exe' -m pip check
git diff --check
```

The source-only Python compilation check used the existing ML virtual
environment's interpreter and compiled Python files under `ml/`, `python-rag/`,
and `tests/`, excluding `.venv` and `__pycache__` paths. It did not recursively
compile third-party packages in either virtual environment.

Earlier audit checks also recorded PHP PDO `SELECT 1`, read-only schema metadata,
temporary loopback ML/RAG health checks, PHP-to-Python contract checks, and
malformed registration/CSRF probes. Details are in the full audit and
organization audit.

## Blocked or not run

| Check | Status / reason |
|---|---|
| Valid login, registration, CRUD, transaction/rollback, role switching | Not run against a disposable DB/account set. The regular `medconnect` schema was not written. |
| Database migration and schema initialization | Not run against a disposable schema. No destructive command was issued. |
| Live Gemini calls | Skipped; paid external API use was not required. |
| ML inference against patient rows | Not run; no test patient records used. |
| Authenticated admin actions, reset submission, exports | Not submitted. |
| Browser visual/layout/console checks at desktop/tablet/mobile | Not verified; no reliable authenticated browser automation was available. |
| IoT physical sensor run | Not run; no device request or hardware was used. |

No PHI was used and no production database records or schema were changed.

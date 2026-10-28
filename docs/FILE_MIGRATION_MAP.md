# File Migration and Change Map

The organization pass moved setup material and the administrator implementation
sources. Root compatibility routes remain in place for URL and relative-asset
compatibility. The complete rationale and verification are in
[project-organization-audit.md](project-organization-audit.md).

## Moves completed

| Previous path | Current path | Reason and verification |
|---|---|---|
| `admin-dashboard.php` | `admin/dashboard.php` + root `admin-dashboard.php` compatibility shim | Groups the implementation under admin while retaining existing redirects, form URLs, and browser assets. PHP lint, static references, and HTTP redirect check passed. |
| `admin-action.php` | `admin/actions/admin-action.php` + root compatibility shim | Groups the management POST handler under admin/actions; the existing form target remains unchanged. PHP lint and include checks passed. |
| `admin-delete-patient.php` | `admin/actions/delete-patient.php` + root compatibility shim | Groups the patient-deletion handler under admin/actions; existing CSRF and authorization behavior remains in the implementation. PHP lint/include checks passed. |
| `admin-login.php` | `admin/login.php` + root compatibility shim | Groups authentication implementation under admin; existing login form endpoint remains unchanged. PHP lint/include checks and HTTP redirect check passed. |
| `read me .txt/sql.txt` | `database/setup.sql` | Canonical new-install schema and local fixtures; contents hash-checked against checkpoint. |
| `read me .txt/migrate-verification.sql` | `database/migrations/legacy-add-verification-status.sql` | Versioned legacy migration; applies only if the column is absent. Hash-checked. |
| `read me .txt/instruction.txt` | `docs/setup/xampp.md` | XAMPP setup guide; README links updated. |
| `read me .txt/iot_esp32_MAX30102_K12/iot_esp32_MAX30102_K12.txt` | `docs/device/esp32-max30102.ino` | Device sample moved to docs and developer network values replaced with placeholders. |
| `read me .txt/.htaccess` | `docs/.htaccess` | Retained direct HTTP denial for documentation. |
| `includes/db_connection.php` (connection implementation) | `config/database.php` (canonical constructor) plus `includes/db_connection.php` (compatibility shim) | Centralizes the PDO constructor while preserving all existing include paths and `$conn` consumers. PHP lint and static include resolution passed. |

Added `.htaccess` rules under `admin/`, `config/`, `includes/`, and `database/`
to deny direct requests for internal implementation/configuration/schema files.
Added `tests/requirements.txt` for the development test dependency. No
application implementation was deleted; legacy PHP route files are shims.

## Active routes retained

| Path pattern | Why it remains |
|---|---|
| `admin-dashboard.php`, `admin-action.php`, `admin-delete-patient.php`, `admin-login.php`, `admin-login.html` | Root compatibility routes retained for administrator form actions, redirects, and sidebar links. |
| `doctor-dashboard.php`, root patient/doctor pages | Existing dashboard URLs and browser/session flows. |
| `save_data.php`, `gemini-chat.php`, health/API PHP routes | ESP32 or browser integration contracts. |
| `doctor/`, `patient/` handlers | Role-specific login and registration handlers already grouped by role. |
| `includes/`, `css/`, `js/`, `ml/`, `python-rag/` | Existing shared/service boundaries; moving them would require a broader compatibility migration. |

## Related changes (not moves)

| File | Change | Check |
|---|---|---|
| `patient/register_patient.php`, `doctor/register_doctor.php` | Validate request before database connection. | PHP lint and malformed-input/CSRF redirect probes passed; no account inserted. |
| `config/database.php`, `includes/db_connection.php` | Canonical PDO constructor with a backward-compatible include shim; preserve `$conn` as PDO. | PHP lint, static include resolution, and read-only DB check recorded in `TEST_REPORT.md`. |
| `README.md`, audit documents | Correct stale setup instructions and record architecture/test evidence. | `git diff --check`. |
| `admin/dashboard.php`, `css/admin-dashboard.css` | Admin workspace styling refinement; admin implementation moved under `admin/`, with root routes retained as shims. | PHP lint, route redirects, include targets, and stylesheet served 200. |
| `admin-login.html`, `admin-reset.php`, `css/admin-auth.css`, `js/admin-auth.js` | Shared dark admin authentication style, labels, feedback, password visibility. | Reset PHP lint, JS syntax, assets served 200, CSRF endpoint shape checked. |

No database table, column, index, or record was changed during the organization
or UI work. The checkpoint bundle path is recorded in
`project-organization-audit.md`.

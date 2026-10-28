# MedConnect Database Setup

## Existing database

The configured schema name is `medconnect`. PHP connects through the canonical
`config/database.php` PDO implementation. `includes/db_connection.php` remains
as a compatibility shim for existing routes. The configuration
contract is `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and
`DB_CONNECT_TIMEOUT`; the actual local values belong in the ignored root `.env`
or Apache's server environment. Do not put credentials in PHP, HTML, browser
JavaScript, or this document.

PDO uses `utf8mb4`, exception mode, native prepared statements, and a bounded
connection timeout. Connection failures log only the SQLSTATE server-side and
receive a generic user-facing response.

## Schema contents

`database/setup.sql` is for a **new local development database only**. The core
schema has these tables and relationships:

- `doctors` — unique username/email; clinician verification status.
- `patients` — unique username/email; optional assigned `doctor_id` FK.
- `appointments` — patient and doctor FKs.
- `health_metrics` — patient FK and timestamped vital fields.
- `patient_suggestions` and `patient_prescriptions` — doctor, patient, and
  appointment FKs.
- `password_reset_otps` — role/account lookup and expiry/attempt tracking.
- `admin_credentials` — administrator username and password hash.
- `ml_predictions` is managed separately by
  `ml/migrations/001_create_ml_predictions.sql` and references `patients`.

The setup SQL includes local demonstration accounts. Do not expose those
fixtures in production and do not import setup SQL over an existing database.
The historical `database/migrations/legacy-add-verification-status.sql` is
only for a pre-existing doctors table missing that column. Inspect first.

## New local database in phpMyAdmin

1. Start MySQL from the XAMPP Control Panel.
2. Create a **new, empty** database named `medconnect` for a fresh local
   installation, or choose a separately named disposable test database.
3. Select that database in phpMyAdmin and import `database/setup.sql`.
4. Set DB environment values in the ignored root `.env` and ensure Apache can
   read them.
5. Start Apache, open `http://localhost/medconnect/`, and confirm the public
   page loads. Do not test writes using personal or production records.

The setup script itself issues `CREATE DATABASE IF NOT EXISTS medconnect` and
`USE medconnect`; therefore it is not safe as a generic test fixture against a
different schema name. Do not run it to test migrations.

## Existing installation and migrations

No core schema migration is required by the current PHP UI changes. The
read-only audit observed the existing schema and its relationships; it made no
database changes. If the optional ML table is absent, test the migration against
a disposable schema first, then review and apply
`ml/migrations/001_create_ml_predictions.sql` under a controlled maintenance
window. The legacy doctor verification migration must not be run if the column
already exists.

To test safely, provision a separately named empty database and use a
test-only environment file/configuration that points only to it. Never point
CRUD or migration tests at a personal `medconnect` database. No automated
database CRUD or transaction test is claimed until this isolated database is
available.

See [project audit](PROJECT_AUDIT.md), the schema file, and the full audit for
actual connection and test status.

# MedConnect

MedConnect is a local IoT-enabled health-monitoring platform for patients, doctors, and administrators. It collects health measurements, stores them in MySQL, displays them through PHP dashboards, and provides a protected health-information assistant.

> **Important:** MedConnect is an educational/student project. Its readings, analytics, and assistant responses are informational only. They are not a diagnosis, emergency alert, prescription, or replacement for a qualified clinician.

## Features

- Patient registration, login, profile, appointments, readings, prescriptions, and history
- Doctor registration, verification, assigned-patient access, appointments, readings, and prescriptions
- Administrator login, doctor approval/rejection, patient management, and CSV export
- ESP32 integration for heart rate, SpO2, temperature, and blood-pressure data
- Patient AI Analytics using an Isolation Forest anomaly-screening model
- Responsive HTML/CSS/JavaScript dashboards
- SMS password recovery through Twilio
- Authenticated RAG health assistant with approved-document sources
- Database-backed assistant fallbacks for readings, appointments, doctors, departments, prescriptions, and analytics guidance
- Direct Gemini responses for general-information questions

## Dashboard theme and navigation

The shared dashboard presentation is provided by:

- `css/theme.css` for dark/light design tokens, responsive sidebar, cards, forms, tables, focus states, and reduced-motion behavior
- `css/app-ui.css` for the shared dashboard visual language on public, authentication, utility, and content pages
- `js/theme-init.js` for early theme application before page content renders
- `js/design-switch.js` for the accessible persisted dark/light control
- `js/dashboard-shell.js` for the responsive patient, doctor, and administrator sidebar

Dark mode is the default. The selected mode is stored in the browser under
`medconnect-dark-mode`; selecting light mode provides the alternative palette.
The sidebar uses only existing application routes and dashboard sections, so it
does not add fake features or data.

## Technology stack

### Web application

- **PHP 8.x**: server-side pages, sessions, authorization, database queries, and APIs
- **HTML5**: application pages and accessible dashboard markup
- **CSS3**: responsive layouts and assistant styling
- **JavaScript**: dashboard interactions, charts, assistant requests, and service-worker registration
- **Bootstrap and Bootstrap Icons**: dashboard components and icons loaded by the existing pages
- **MySQL/MariaDB**: patients, doctors, appointments, readings, prescriptions, suggestions, and password-reset data
- **PDO prepared statements**: parameterized database access
- **Apache through XAMPP**: local PHP web server

### IoT and analytics

- **ESP32**: sends sensor readings to `save_data.php`
- **MAX30102**: heart-rate and oxygen-saturation sensor
- **Temperature sensor**: body-temperature measurement
- **Python and scikit-learn**: Isolation Forest screening in `ml/predict_health.py`
- **Chart.js**: health-history visualization where enabled

### RAG assistant

- **Python 3.11+**: RAG service runtime
- **FastAPI and Uvicorn**: local API at `127.0.0.1:8000`
- **sentence-transformers**: document embeddings using `all-MiniLM-L6-v2`
- **FAISS**: normalized-vector similarity search
- **Google Gemini API**: grounded MedConnect answers, mixed questions, and direct general-information responses
- **python-dotenv**: environment configuration
- **PyMuPDF**: PDF text extraction during ingestion

## Architecture

The complete component, data-flow, database, RAG, and connection diagrams are
in [ARCHITECTURE.md](./ARCHITECTURE.md).

The browser communicates with the PHP application, not directly with Gemini or the RAG service:

```text
Browser
  |
  v
Apache/PHP dashboard
  |-- session and CSRF validation
  |-- patient/doctor authorization
  |-- MySQL queries for private records
  |
  `--> FastAPI RAG service (loopback only)
          |-- token validation
          |-- approved-document retrieval
          |-- Gemini grounded generation
          |-- direct Gemini route for general questions
          `-- sanitized, source-aware response
```

Patient readings, appointments, and prescriptions are answered by scoped PHP/MySQL queries. Private readings and patient identifiers are not sent to Gemini, the vector index, or Python conversation history. MedConnect questions use approved indexed documents; missing evidence is reported without inventing application facts. Programming and other general questions go directly to Gemini without retrieval. Requests for private records are denied by the Python boundary if they reach it.

## RAG design

### Documents

Place approved `.txt` or `.pdf` references in:

```text
python-rag/documents/
```

The repository includes a small first-party reference document:

```text
python-rag/documents/MedConnect_System_Reference.txt
```

Do not place patient exports, credentials, or unreviewed private documents in this directory.

### Chunking

`python-rag/ingest.py` reads each approved document and creates overlapping text chunks using `python-rag/chunking.py`.

The default configuration is:

- Chunk size: `900` characters
- Overlap: `150` characters
- Whitespace normalized before chunking
- PDF pages retained as source metadata when available

Overlap keeps context that crosses chunk boundaries. The chunk metadata is stored with the document name and page number where available.

### Embeddings and retrieval

Each chunk is embedded with:

```text
sentence-transformers/all-MiniLM-L6-v2
```

Embeddings are normalized and stored in a FAISS inner-product index. Because vectors are normalized, inner product is equivalent to cosine similarity. At query time:

1. The question is normalized.
2. The same embedding model creates a query vector.
3. FAISS returns the top `RAG_TOP_K` chunks.
4. Results below `RAG_MIN_SIMILARITY` are discarded.
5. MedConnect excerpts are sent to Gemini with instructions to answer only from those excerpts; general questions skip steps 1–5 and go directly to Gemini.
6. The PHP UI displays the answer and source metadata returned for the excerpts actually used.

The index is local and generated. It is excluded from Git:

```text
python-rag/data/knowledge.faiss
python-rag/data/chunks.json
```

### Routing

1. PHP checks the authenticated role, CSRF token, and question size.
2. Private data requests are answered only through PHP's parameterized, role-scoped MySQL queries. A doctor-selected patient is checked against assignment/appointment authorization on each request.
3. Python classifies non-private requests locally. MedConnect facts use RAG; general questions use Gemini directly; unsupported MedConnect facts receive a non-fabricated verification response; private or ambiguous data requests are denied.
4. Gemini/provider failures return a sanitized temporary error, never an invented answer. Follow-up history is bounded, expires, and contains only non-private Python conversations.

## Requirements

- Windows with XAMPP, or an equivalent Apache/PHP/MySQL environment
- PHP 8.x with PDO MySQL, sessions, and cURL enabled
- MySQL or MariaDB
- Python 3.11 or newer
- A Google AI Studio Gemini API key for RAG generation
- Optional Twilio credentials for SMS password recovery
- Optional ESP32 and supported sensors

## Local setup

### 1. Install the project

Clone the repository into the XAMPP document root:

```text
C:\xampp\htdocs\medconnect
```

Start **Apache** and **MySQL** from the XAMPP Control Panel.

### 2. Create the database

Only for a **new local development database**, create the database in phpMyAdmin and import:

```text
database\setup.sql
```

The SQL file includes local demonstration doctor and patient accounts that share a test password. Do not expose those fixtures as production accounts. It does not generate health readings. Do not import the schema over an existing MedConnect database; use only the applicable migration for an existing installation.

For an existing database created before doctor verification was added, run:

```text
database\migrations\legacy-add-verification-status.sql
```

That legacy migration is only for an older database whose `doctors` table does
not yet have `verification_status`. The current setup schema already includes
the column; inspect the schema before applying any migration.

### 3. Configure secrets

Copy the root environment template:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

If `.env` already exists, keep it and add/update only the required values; do not replace it with the template.

Set the values in `.env`:

```text
DB_HOST=localhost
DB_PORT=3306
DB_NAME=medconnect
DB_USER=root
DB_PASSWORD=
GEMINI_API_KEY=your_private_key
GEMINI_MODEL=gemini-3.8-flash
EMBEDDING_MODEL=sentence-transformers/all-MiniLM-L6-v2
RAG_SERVICE_TOKEN=long_random_secret_shared_only_by_PHP_and_RAG
RAG_URL=http://127.0.0.1:8000/chat
DEVICE_INGEST_TOKEN=long_random_device_secret
DEVICE_PATIENT_ID=
ML_API_URL=http://127.0.0.1:8001
ML_API_TOKEN=long_random_secret
ADMIN_USERNAME=your_admin_username
ADMIN_PASSWORD_HASH=your_generated_password_hash
```

Never commit `.env`, API keys, database passwords, Twilio credentials, or real patient data.
The sensor endpoint requires an explicit `DEVICE_PATIENT_ID`; it has no default patient target. Configure the actual device-to-patient mapping before sending readings.

For SMS recovery, also configure:

```text
TWILIO_ACCOUNT_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_FROM_NUMBER=+1234567890
```

For first-run administrator access, set `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` in the server-only `.env` file. On the first admin login, MedConnect stores those credentials in `admin_credentials`; later `.env` changes will not overwrite database credentials. Generate a password hash with:

```powershell
C:\xampp\php\php.exe -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

### 4. Install Python dependencies

Install the web/analytics dependencies from the project root:

```powershell
py -m pip install -r requirements.txt
```

Install the RAG dependencies:

```powershell
py -m pip install -r python-rag\requirements.txt
```

For an isolated RAG environment:

```powershell
py -m venv python-rag\.venv
.\python-rag\.venv\Scripts\Activate.ps1
python -m pip install -r python-rag\requirements.txt
```

### 5. Build the RAG index

After adding or changing approved documents:

```powershell
python-rag\.venv\Scripts\python.exe python-rag\ingest.py
```

Rebuild the index whenever documents change. Ingestion replaces the generated index so removed documents are not retained.

### 6. Start the RAG service

Use the Windows helper, or run Uvicorn from the isolated environment:

```powershell
.\run-rag.cmd
```

Or start it manually:

```powershell
python -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
```

Verify service health:

```powershell
Invoke-WebRequest http://127.0.0.1:8000/health
```

Expected response:

```json
{"status":"ok"}
```

Keep the service bound to `127.0.0.1` for local XAMPP use. The browser should never call port 8000 directly.

### 7. Open the website

Open:

```text
http://localhost/medconnect/
```

Useful pages:

- `index.html`: landing page
- `choose-role.html`: patient/doctor selection
- `patient-login.html`: patient login
- `doctor-login.html`: doctor login
- `admin-login.html`: administrator login
- `patient-dashboard.php`: patient workspace and assistant
- `doctor-dashboard.php`: doctor workspace and assistant
- `patient-prediction.php`: AI Analytics
- `forgot-password.php`: SMS password recovery

## Assistant examples

Database-backed questions:

- `What is my latest heart rate?`
- `Show my readings from the last seven days.`
- `Compare my latest SpO2 with the previous one.`
- `What appointments do I have?`
- `How many doctors are available?`
- `Suggest a department for heart-related concerns.`
- `Do I have any prescriptions?`

Approved-document questions:

- `What technologies does the MedConnect web application use?`
- `How does the RAG knowledge assistant work?`
- `What is stored in the health_metrics table?`

General programming and knowledge questions go directly to Gemini without document retrieval. MedConnect-specific questions use approved documents; when no source supports a factual detail, the assistant says it cannot verify that detail rather than guessing. The Python service never has access to private patient records.

## Testing and verification

PHP syntax checks:

```powershell
C:\xampp\php\php.exe -l gemini-chat.php
C:\xampp\php\php.exe -l patient-dashboard.php
C:\xampp\php\php.exe -l doctor-dashboard.php
```

RAG unit tests:

```powershell
.\python-rag\.venv\Scripts\python.exe -m pip install -r tests\requirements.txt
.\python-rag\.venv\Scripts\python.exe -m pytest python-rag\tests -q
.\ml\.venv\Scripts\python.exe -m pip install -r tests\requirements.txt
.\ml\.venv\Scripts\python.exe -m pytest ml\tests -q
```

Manual checks:

1. Confirm Apache and MySQL are running.
2. Confirm `http://127.0.0.1:8000/health` returns `status: ok`.
3. Sign in as a patient and verify the dashboard loads when RAG is stopped.
4. Test latest readings, timestamps, comparisons, summaries, and missing values.
5. Test appointments, prescriptions, doctors, departments, and department suggestions.
6. Test an approved-document question and confirm the source is displayed.
7. Test an unrelated question and confirm it receives a direct general Gemini answer.
8. Stop Gemini/RAG and confirm the dashboard remains available with a sanitized error.
9. Test the doctor workflow and confirm unassigned patient IDs return `403`.
10. Test mobile layout, keyboard submission, and text-only response rendering.

## Security and privacy

- Keep Gemini, Twilio, database, administrator, and RAG-token values server-side.
- Do not expose `.env` or the RAG token to browser JavaScript.
- The PHP endpoint requires an authenticated patient or doctor session and CSRF token.
- Patient readings are queried with the authenticated patient ID.
- Doctor patient-record requests are re-authorized against an approved doctor's explicit patient assignment or appointment relationship.
- RAG requests require `X-MedConnect-Token`.
- Use prepared statements for database queries.
- Administrator CSV exports stream after server-side role verification; the legacy `exports/` directory is blocked from web access.
- Review all documents before adding them to the approved RAG corpus.

## Project structure

```text
medconnect/
|-- css/                       Stylesheets, including assistant UI
|-- database/                  New-install SQL and historical migrations (web access denied)
|   |-- migrations/             Explicit schema changes for older installations
|   `-- setup.sql               Initial schema plus local-only demo accounts
|-- doctor/                    Doctor registration/login pages
|-- docs/                      Architecture, setup, and device documentation (web access denied)
|   |-- device/                 ESP32 example firmware
|   |-- setup/                  Local XAMPP setup
|   `-- project-organization-audit.md  Inventory, changes, and verification
|-- exports/                   Legacy export folder (web access denied)
|-- includes/                  Database, authentication, and SMS helpers
|-- js/                        Dashboard and assistant JavaScript
|-- ml/                        Python anomaly-screening bridge/model
|-- patient/                   Patient registration/login pages
|-- python-rag/                FastAPI RAG service
|   |-- documents/             Approved source documents
|   |-- tests/                 RAG unit tests
|   |-- app.py                 FastAPI routes and fallback flow
|   |-- chunking.py            Text chunking
|   |-- gemini_service.py      Grounded and general Gemini calls
|   |-- ingest.py              Index builder
|   |-- retrieval.py           FAISS retrieval
|-- tests/                     Shared test-only Python dependencies
|   `-- requirements.txt       pytest; kept out of service dependencies
|-- gemini-chat.php            Authenticated PHP assistant bridge
|-- patient-dashboard.php      Patient workspace
|-- doctor-dashboard.php       Doctor workspace
|-- patient-prediction.php     Patient analytics page
|-- save_data.php              ESP32 data endpoint
|-- run-rag.cmd                Windows RAG startup helper
|-- .env.example               Secret configuration template
|-- README.md                  This guide
`-- ARCHITECTURE.md            Detailed architecture, connections, and RAG flow
```

## Deployment notes

For deployment, use HTTPS, a managed MySQL database, server-side environment variables, restricted network access to the RAG service, and a production process manager for Uvicorn. Do not use the development server as a public internet service. Import the schema from `database/setup.sql`, configure the documented `DB_*`, `ML_*`, `DEVICE_INGEST_TOKEN`, `GEMINI_*`, and `RAG_*` values, and keep generated exports outside public web access.

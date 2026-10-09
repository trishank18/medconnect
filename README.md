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
- Gemini general-information fallback for questions outside the approved project documents

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
- **Google Gemini API**: grounded answer generation and general-information fallback
- **python-dotenv**: environment configuration
- **PyMuPDF**: PDF text extraction during ingestion

## Architecture

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
          `-- Gemini general fallback when no document matches
```

Patient readings, appointments, and prescriptions are answered by scoped PHP/MySQL queries. Private readings and patient identifiers are not sent to Gemini. Knowledge questions use only approved indexed documents. Questions without a relevant document may use the explicitly labelled Gemini general-information fallback.

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
5. Relevant excerpts are sent to Gemini with instructions to answer only from those excerpts.
6. The PHP UI displays the answer and verified document/page sources.

The index is local and generated. It is excluded from Git:

```text
python-rag/data/knowledge.faiss
python-rag/data/chunks.json
```

### Fallback order

The assistant uses this order:

1. Greeting and clarification responses
2. Authenticated PHP/MySQL fallbacks for private application data
3. Approved-document RAG retrieval and grounded Gemini response
4. Gemini general-information fallback when no approved document matches
5. Sanitized temporary-unavailability response if Gemini is unavailable

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

Create a database in phpMyAdmin and import:

```text
read me .txt\sql.txt
```

For an existing database created before doctor verification was added, run:

```text
read me .txt\migrate-verification.sql
```

### 3. Configure secrets

Copy the root environment template:

```powershell
Copy-Item .env.example .env
```

Set the values in `.env`:

```text
GEMINI_API_KEY=your_private_key
GEMINI_MODEL=gemini-3.8-flash
EMBEDDING_MODEL=sentence-transformers/all-MiniLM-L6-v2
RAG_SERVICE_TOKEN=long_random_secret_shared_only_by_PHP_and_RAG
RAG_URL=http://127.0.0.1:8000/chat
```

Never commit `.env`, API keys, database passwords, Twilio credentials, or real patient data.

For SMS recovery, also configure:

```text
TWILIO_ACCOUNT_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_FROM_NUMBER=+1234567890
```

For administrator access, configure `ADMIN_USERNAME` and a password hash. Generate a hash with:

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
py -m pip install -r python-rag\requirements.txt
```

### 5. Build the RAG index

After adding or changing approved documents:

```powershell
py python-rag\ingest.py
```

Rebuild the index whenever documents change. Ingestion replaces the generated index so removed documents are not retained.

### 6. Start the RAG service

Use the Windows helper:

```powershell
.\run-rag.cmd
```

Or start it manually:

```powershell
py -m uvicorn app:app --app-dir python-rag --host 127.0.0.1 --port 8000
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

General-information fallback questions may be answered by Gemini when no approved document matches. The response is labelled as general information and does not have access to private patient records.

## Testing and verification

PHP syntax checks:

```powershell
C:\xampp\php\php.exe -l gemini-chat.php
C:\xampp\php\php.exe -l patient-dashboard.php
C:\xampp\php\php.exe -l doctor-dashboard.php
```

RAG unit tests:

```powershell
py -m unittest discover python-rag\tests
```

Manual checks:

1. Confirm Apache and MySQL are running.
2. Confirm `http://127.0.0.1:8000/health` returns `status: ok`.
3. Sign in as a patient and verify the dashboard loads when RAG is stopped.
4. Test latest readings, timestamps, comparisons, summaries, and missing values.
5. Test appointments, prescriptions, doctors, departments, and department suggestions.
6. Test an approved-document question and confirm the source is displayed.
7. Test an unrelated question and confirm the general fallback is labelled.
8. Stop Gemini/RAG and confirm the dashboard remains available with a sanitized error.
9. Test the doctor workflow and confirm unassigned patient IDs return `403`.
10. Test mobile layout, keyboard submission, and text-only response rendering.

## Security and privacy

- Keep Gemini, Twilio, database, administrator, and RAG-token values server-side.
- Do not expose `.env` or the RAG token to browser JavaScript.
- The PHP endpoint requires an authenticated patient or doctor session and CSRF token.
- Patient readings are queried with the authenticated patient ID.
- Doctor patient-record requests are re-authorized against approved doctor appointments.
- RAG requests require `X-MedConnect-Token`.
- Use prepared statements for database queries.
- Generated CSV exports may contain personal health information and are ignored by Git.
- Review all documents before adding them to the approved RAG corpus.

## Project structure

```text
medconnect/
|-- css/                       Stylesheets, including assistant UI
|-- doctor/                    Doctor registration/login pages
|-- exports/                   Local generated CSV exports
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
|-- read me .txt/sql.txt       MySQL schema
|-- gemini-chat.php            Authenticated PHP assistant bridge
|-- patient-dashboard.php      Patient workspace
|-- doctor-dashboard.php       Doctor workspace
|-- patient-prediction.php     Patient analytics page
|-- save_data.php              ESP32 data endpoint
|-- run-rag.cmd                Windows RAG startup helper
|-- .env.example               Secret configuration template
`-- README.md                  This guide
```

## Deployment notes

For deployment, use HTTPS, a managed MySQL database, server-side environment variables, restricted network access to the RAG service, and a production process manager for Uvicorn. Do not use the development server as a public internet service. Import the schema from `read me .txt/sql.txt`, configure `DB_*`, `PYTHON_BIN`, `GEMINI_*`, and `RAG_*` values, and keep generated exports outside public web access.

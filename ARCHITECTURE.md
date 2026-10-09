# MedConnect Architecture

This document describes the current MedConnect application, its connections, the
RAG pipeline, and the path from an ESP32 measurement to a user-visible answer.

> MedConnect is an educational health-monitoring project. It is not a clinical
> decision system and must not be used as a substitute for a qualified clinician.

## 1. System context

```mermaid
flowchart LR
    Patient[Patient browser]
    Doctor[Doctor browser]
    Admin[Administrator browser]
    ESP32[ESP32 sensors]
    Apache[Apache / XAMPP]
    PHP[PHP application]
    MySQL[(MySQL / MariaDB)]
    RAG[FastAPI RAG service<br/>127.0.0.1:8000]
    Gemini[Google Gemini API]
    Twilio[Twilio SMS API]
    ML[Python health analytics]

    Patient -->|HTTPS/HTTP pages and POST requests| Apache
    Doctor -->|HTTPS/HTTP pages and POST requests| Apache
    Admin -->|HTTPS/HTTP pages and POST requests| Apache
    ESP32 -->|POST + device token| Apache
    Apache --> PHP
    PHP -->|PDO / MySQLi prepared queries| MySQL
    PHP -->|server-to-server HTTP + RAG token| RAG
    RAG -->|API key kept server-side| Gemini
    PHP -->|password recovery request| Twilio
    PHP -->|analytics input| ML
    ML -->|prediction result| PHP
```

### Trust boundaries

1. **Browser boundary**: browsers may submit user-controlled form and assistant
   input. Authentication, authorization, CSRF checks, and output escaping happen
   in PHP.
2. **Device boundary**: the ESP32 is not trusted by URL parameters alone.
   `save_data.php` accepts POST data only when the configured
   `X-MedConnect-Device-Token` matches.
3. **Database boundary**: MySQL contains private patient data. Queries must use
   the authenticated patient ID or an authorized doctor-patient appointment.
4. **RAG boundary**: the browser never calls FastAPI or Gemini directly. PHP
   calls the loopback RAG service with `X-MedConnect-Token`.
5. **External-service boundary**: only the RAG service sends approved excerpts
   and general-information prompts to Gemini. Patient readings and identifiers
   are handled by PHP/MySQL and are not included in Gemini prompts.

## 2. Application layers

```mermaid
flowchart TB
    UI[HTML / CSS / JavaScript dashboards]
    Routes[PHP pages and endpoints]
    Auth[Sessions, role checks, CSRF, ownership checks]
    Domain[Assistant routing, appointments, prescriptions,<br/>readings, analytics]
    Data[(MySQL tables)]
    Services[RAG bridge, Twilio bridge, ML bridge]

    UI --> Routes
    Routes --> Auth
    Auth --> Domain
    Domain --> Data
    Domain --> Services
    Services --> Data
```

### Main web surfaces

| Surface | Responsibility |
| --- | --- |
| `index.html`, role and login pages | Public entry and authentication UI |
| `patient-dashboard.php` | Patient readings, appointments, prescriptions, analytics, and assistant |
| `doctor-dashboard.php` | Authorized patient views, appointments, and prescriptions |
| `admin-dashboard.php` | Doctor verification, patient administration, appointments, and exports |
| `gemini-chat.php` | Authenticated assistant router and RAG gateway |
| `save_data.php` | Device measurement ingestion |
| `add-health.php` | Authenticated/manual health-record insertion |
| `book_appointment.php` | Patient appointment creation |
| `get_patient_metrics.php` | Authorized metrics retrieval |
| `patient-prediction.php`, `predict_health.php` | Health analytics UI and prediction bridge |

## 3. Database relationships

```mermaid
erDiagram
    PATIENTS ||--o{ HEALTH_METRICS : records
    PATIENTS ||--o{ APPOINTMENTS : books
    DOCTORS ||--o{ APPOINTMENTS : receives
    PATIENTS ||--o{ PATIENT_PRESCRIPTIONS : receives
    DOCTORS ||--o{ PATIENT_PRESCRIPTIONS : writes
    APPOINTMENTS ||--o{ PATIENT_PRESCRIPTIONS : supports
    PATIENTS ||--o{ PATIENT_SUGGESTIONS : receives

    PATIENTS {
        int id PK
        string fullname
        string email
        string password
        int doctor_id FK
    }
    DOCTORS {
        int id PK
        string fullname
        string email
        string specialization
        string verification_status
    }
    APPOINTMENTS {
        int id PK
        int patient_id FK
        int doctor_id FK
        date appointment_date
        time appointment_time
        string status
    }
    HEALTH_METRICS {
        int id PK
        int patient_id FK
        int heart_rate
        int spo2
        decimal temperature
        int blood_pressure_sys
        int blood_pressure_dia
        datetime recorded_at
    }
    PATIENT_PRESCRIPTIONS {
        int id PK
        int patient_id FK
        int doctor_id FK
        int appointment_id FK
        text prescription
        string medication
        string dosage
    }
    PATIENT_SUGGESTIONS {
        int id PK
        int patient_id FK
        text suggestion
    }
```

The schema source is [`read me .txt/sql.txt`](<read me .txt/sql.txt>). The
database is the source of truth for private records; generated CSV exports are
operational artifacts and must not be used as the RAG corpus.

## 4. Health-reading connection flow

```mermaid
sequenceDiagram
    participant S as ESP32 sensors
    participant A as Apache/PHP
    participant D as MySQL
    participant P as Patient dashboard

    S->>S: Read MAX30102 heart rate and SpO2
    S->>S: Read temperature sensor
    S->>A: POST /medconnect/save_data.php<br/>form data + device token
    A->>A: Verify method, token, ranges, and patient configuration
    A->>D: INSERT health_metrics
    D-->>A: Insert result
    A-->>S: Sanitized success or error
    P->>A: Authenticated reading request
    A->>A: Scope query to session patient ID
    A->>D: SELECT authorized readings
    D-->>A: Timestamped measurements
    A-->>P: Escaped dashboard data
```

The device endpoint must receive `DEVICE_INGEST_TOKEN` from server environment
configuration. The device example is in
[`read me .txt/iot_esp32_MAX30102_K12/iot_esp32_MAX30102_K12.txt`](<read me .txt/iot_esp32_MAX30102_K12/iot_esp32_MAX30102_K12.txt>).

## 5. Assistant routing and connections

```mermaid
flowchart TD
    Q[Assistant question]
    Session[Authenticated PHP session]
    Local[Local intent handlers]
    DB[Scoped MySQL handlers]
    RAGCall[POST /chat to FastAPI]
    Retrieve[FAISS retrieval]
    Grounded[Gemini grounded answer]
    General[Gemini general-information fallback]
    Safe[Sanitized unavailable / abstention response]
    Sources[Source metadata returned to UI]

    Q --> Session
    Session -->|missing/invalid| Safe
    Session -->|valid| Local
    Local -->|greeting, clarification, department, booking guidance| Sources
    Local -->|readings, appointments, doctors, prescriptions, analytics| DB
    Local -->|approved knowledge or unsupported knowledge| RAGCall
    DB --> Sources
    RAGCall --> Retrieve
    Retrieve -->|relevant approved chunks| Grounded
    Retrieve -->|no relevant chunks| General
    Grounded --> Sources
    General --> Sources
    Grounded -->|provider failure| Safe
    General -->|provider failure| Safe
    Sources --> UI[Assistant response]
```

### Request sequence

```mermaid
sequenceDiagram
    participant B as Browser
    participant PHP as gemini-chat.php
    participant DB as MySQL
    participant R as FastAPI RAG
    participant G as Gemini

    B->>PHP: POST question + assistant CSRF token
    PHP->>PHP: Verify session, role, CSRF, and input
    alt Private record intent
        PHP->>DB: Query using patient/doctor authorization
        DB-->>PHP: Private record rows
        PHP-->>B: Record answer; no Gemini request
    else Knowledge intent
        PHP->>R: POST question + X-MedConnect-Token
        R->>R: Embed query and search approved FAISS index
        alt Relevant chunks found
            R->>G: Grounded prompt with excerpts only
            G-->>R: Answer
        else No relevant chunks
            R->>G: General-information prompt without private data
            G-->>R: Labelled general answer
        end
        R-->>PHP: Answer, sources, or sanitized error
        PHP-->>B: Escaped answer and source list
    end
```

## 6. RAG architecture

```mermaid
flowchart LR
    Docs[Approved TXT/PDF files<br/>python-rag/documents]
    Ingest[ingest.py]
    Extract[Text extraction<br/>TXT or PyMuPDF PDF pages]
    Normalize[Whitespace normalization]
    Chunk[chunking.py<br/>900 chars / 150 overlap]
    Embed[sentence-transformers<br/>all-MiniLM-L6-v2]
    Index[FAISS normalized inner-product index]
    Meta[chunks.json<br/>source/page/text metadata]
    Query[User question from PHP]
    QEmbed[Query embedding]
    Search[Top-K similarity search]
    Filter[Minimum similarity filter]
    Prompt[Grounded prompt builder]
    Gemini[Gemini API]
    Answer[Answer + source metadata]

    Docs --> Ingest --> Extract --> Normalize --> Chunk
    Chunk --> Embed --> Index
    Chunk --> Meta
    Query --> QEmbed --> Search
    Index --> Search --> Filter
    Meta --> Search
    Filter --> Prompt --> Gemini --> Answer
```

### RAG files and responsibilities

| File | Responsibility |
| --- | --- |
| `python-rag/config.py` | Environment variables, paths, model, top-K, and similarity settings |
| `python-rag/ingest.py` | Read approved files, extract text, chunk, embed, and write local index files |
| `python-rag/chunking.py` | Deterministic overlapping character chunking |
| `python-rag/retrieval.py` | Load FAISS and metadata, embed questions, and rank chunks |
| `python-rag/gemini_service.py` | Build grounded/general prompts and call Gemini |
| `python-rag/app.py` | FastAPI `/health` and authenticated `/chat` routes |
| `python-rag/schemas.py` | Request and response models |
| `python-rag/data/knowledge.faiss` | Generated vector index; never hand-edit or commit |
| `python-rag/data/chunks.json` | Generated chunk/source metadata; never hand-edit or commit |

## 7. Chunking in detail

The current chunker uses character offsets so it works for both plain text and
PDF-extracted text:

```text
normalized document text
        |
        v
start = 0
while start < text length:
    end = min(start + 900, text length)
    emit text[start:end]
    start = end - 150
```

For a 2,400-character document, the approximate windows are:

```text
chunk 1: characters   0 - 899
chunk 2: characters 750 - 1649
chunk 3: characters 1500 - 2399
```

The 150-character overlap preserves context when a definition or procedure
crosses a boundary. Each emitted chunk retains:

- source filename;
- page number for PDF content, when available;
- chunk index;
- chunk text;
- document metadata used to display citations.

Chunking does not ingest MySQL rows, patient exports, `.env` files, or browser
conversation history. To rebuild after an approved document changes:

```powershell
py python-rag\ingest.py
```

The rebuild replaces the generated FAISS index and metadata, so removed source
documents are not retained in the next local index.

## 8. Retrieval and answer policy

1. PHP authenticates the user before forwarding a knowledge question.
2. FastAPI verifies `X-MedConnect-Token`.
3. The query is embedded with the same model used during ingestion.
4. FAISS returns the configured top-K candidates.
5. Candidates below `RAG_MIN_SIMILARITY` are removed.
6. Relevant excerpts are sent to Gemini with a source-grounding instruction.
7. The response includes source filename/page metadata.
8. If no approved excerpt is relevant, the service may use Gemini's explicitly
   labelled general-information fallback.
9. Gemini/provider failures become a sanitized temporary-unavailability response.

Private readings, appointments, prescriptions, patient names, session IDs, and
database exports stay in the PHP/MySQL path. They are not used to create the
embeddings or included in RAG prompts.

## 9. Runtime configuration and connection checklist

| Connection | Configuration | Expected direction |
| --- | --- | --- |
| Browser -> Apache | `http://localhost/medconnect/` | Browser to PHP |
| PHP -> MySQL | `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Server only |
| PHP -> RAG | `RAG_URL`, `RAG_SERVICE_TOKEN` | Loopback server-to-server |
| RAG -> Gemini | `GEMINI_API_KEY`, `GEMINI_MODEL` | RAG server only |
| ESP32 -> PHP | `DEVICE_INGEST_TOKEN`, `save_data.php` | Device POST |
| PHP -> Twilio | `TWILIO_*` values | Server only |
| PHP -> analytics | Python executable and `ml/predict_health.py` | Server-side process |

Never put any token, API key, database password, or patient export into
`python-rag/documents/`, browser JavaScript, HTML, Git, Docker images, or chat
messages.

## 10. Operational startup order

1. Start MySQL and Apache in XAMPP.
2. Confirm the database schema is imported.
3. Configure the root `.env` without committing it.
4. Install Python dependencies.
5. Run `py python-rag\ingest.py` after approved documents change.
6. Start FastAPI with `run-rag.cmd` or Uvicorn on `127.0.0.1:8000`.
7. Confirm `GET http://127.0.0.1:8000/health`.
8. Open `http://localhost/medconnect/` and sign in.

The dashboards and database-backed features should remain available if the RAG
service or Gemini is temporarily unavailable; only knowledge-answer requests
should degrade to a sanitized error or fallback response.

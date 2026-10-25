# MedConnect Random Forest Regression

This service trains offline on `dataset/iot_health_monitoring_dataset.csv` and
serves experimental next-observed-reading estimates from the authenticated
patient's stored `health_metrics` rows. The CSV is never used as live
prediction input.

## Model objective and schema

Each target is the subsequent reading for the same dataset patient, limited to
an observation within 24 hours. Rows are split chronologically into training,
validation, and held-out test windows. The target column is excluded from its
own predictors, and patient/device identifiers and timestamps are never model
features. Temperature is converted from the dataset's Fahrenheit values to
Celsius. The live database schema stores Celsius temperature.

The predictors are the vital columns shared by the dataset and
`health_metrics`: `heart_rate`, `spo2`, and `temperature`. A target is only
served when its saved artifact has suitable training data. The source file has
5,094 rows, no missing values, and no exact duplicate rows; its exact metadata
is recorded in each report under `ml/reports/`. Its measurement provenance is
not independently verified, so these outputs are not clinical validation.

## Windows/XAMPP setup

From PowerShell in `C:\xampp\htdocs\medconnect`:

```powershell
py -3 -m venv ml\.venv
.\ml\.venv\Scripts\python.exe -m pip install -r ml\requirements.txt
```

Set `ML_API_TOKEN`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and
`DB_PASSWORD` in the environment used by FastAPI. Configure the same
`ML_API_TOKEN` and `ML_API_URL=http://127.0.0.1:8001` in Apache's server
environment. Use [`.env.example`](.env.example) as a variable reference; do
not put secrets in a web-accessible file.

Apply [the migration](migrations/001_create_ml_predictions.sql) once in the
existing `medconnect` database. It only creates the separate prediction table;
it does not modify `patients` or `health_metrics`.

## Train and run

Train or retrain all supported targets:

```powershell
.\ml\.venv\Scripts\python.exe ml\train_model.py
```

This writes the five target artifacts under `ml/models/`, updates
`ml/models/latest.json`, and writes a timestamped report under `ml/reports/`.
The report includes held-out MAE, RMSE, R2, and persistence-baseline metrics.

Start the local-only API on port 8001:

```powershell
$env:ML_API_TOKEN = '<same token configured for Apache>'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_NAME = 'medconnect'
$env:DB_USER = 'root'
$env:DB_PASSWORD = ''
.\ml\.venv\Scripts\python.exe -m uvicorn api:app --app-dir ml --host 127.0.0.1 --port 8001
```

## API

- `GET /health` reports artifact and service configuration status.
- `GET /model-info` requires `X-ML-Token` and returns feature schemas,
  versions, dataset metadata, and evaluation metrics.
- `POST /predict/patient/{patient_id}` requires `X-ML-Token`. It retrieves
  chronological rows directly from MySQL and returns predictions, data-quality
  status, target horizon, model version, and evaluation metrics.

The PHP session and existing patient/doctor authorization remain the source of
authorization. FastAPI's token authenticates only the PHP service, not a
browser or a patient's right to view another patient's data. PHP never sends
measurements or the ML token to browser JavaScript.

## PHP integration behavior

`add-health.php` commits the authenticated patient's real measurement first,
then calls the protected patient endpoint best-effort. The patient dashboard
also requests current estimates server-side and stores them separately in
`ml_predictions`. A service outage never rolls back a real reading. Doctor
dashboard data is already scoped by the existing doctor appointment query.
Predictions never update `health_metrics`; `actual_value` remains nullable
until a later observed reading is reconciled.

## Tests

```powershell
.\ml\.venv\Scripts\python.exe -m unittest discover -s ml\tests -v
.\ml\.venv\Scripts\python.exe -m py_compile ml\api.py ml\db.py ml\preprocess.py ml\train_model.py
C:\xampp\php\php.exe -l includes\ml_client.php
C:\xampp\php\php.exe -l add-health.php
C:\xampp\php\php.exe -l patient-dashboard.php
```

The automated API tests mock only the database connector. End-to-end MySQL
validation must be run with the local XAMPP database and real patient records.

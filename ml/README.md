# MedConnect Random Forest module

This module adds experimental regression estimates to the existing PHP/MySQL app. It does not change `health_metrics`; predictions are stored in `ml_predictions` after applying [`migrations/001_create_ml_predictions.sql`](migrations/001_create_ml_predictions.sql). The existing `ml/predict_health.py` Isolation Forest endpoint remains separate and is not used by this regression service.

## Dataset inspection and targets

Training discovers the single CSV in `../dataset/` and writes a full analysis to `reports/`: filename, original source columns and dtypes, row count, missing values, duplicate count, sample rows, patient/device counts, observations per ID, and time range. The inspected file is `iot_health_monitoring_dataset.csv`.

The file has 5,094 records, 17 original columns, zero missing values and zero exact duplicate rows. It spans 2025-09-30 through 2025-10-30. There are 500 patient IDs and 200 device IDs; each patient has 3–20 records (median 10), but device IDs vary for a patient. Timestamp and IDs are grouping metadata and never model predictors. `PATIENT_####` and `DEVICE_####` have generated-looking forms, but the source does not document their provenance. Numeric features include all five MedConnect vitals plus glucose, respiration, activity, sleep, stress, HRV, steps, calories and `health_event`. These names describe measurement-like fields, but the CSV provides no sensor/source metadata to verify which readings are physical, synthetic or derived. The additional values are not in the current MedConnect metric schema and are not used. `health_event` is an integer-coded event label and is excluded as a potential derived/class-label leakage path.

Column mapping: `heart_rate` (BPM) → `heart_rate`; `blood_oxygen` (%) → `spo2`; `blood_pressure_systolic` (mmHg) → `blood_pressure_sys`; `blood_pressure_diastolic` (mmHg) → `blood_pressure_dia`; `body_temperature` → `temperature`. The temperature values cluster around 97–99, so the code treats that column as Fahrenheit and converts it to Celsius. The CSV does not document its measurement provenance or units; that inference should be confirmed with the dataset publisher. The IDs and regular-looking measurements do not establish real sensor provenance. Treat the data as potentially synthetic, not evidence of clinical performance.

Targets are the next observed row for the same `patient_id`, sorted by timestamp, no more than 24 hours later. The target value and identifiers are excluded from predictors; predictor vitals use only the current sensor channels. Temperature is normalized to Celsius. Chronological 70% train, 15% validation and 15% test windows represent future readings for known patients. Samples whose next-reading target would cross a train/validation boundary are removed. Tuning uses validation only, and final metrics use the held-out later segment. A persistence baseline repeats the current measurement for that same target; it is a benchmark only and is not fed to the RF model. Invalid target measurements are removed; invalid predictor vitals become missing and are median-imputed inside the serialized pipeline.

These are experimental estimates of the next dataset reading. They are not diagnoses, verified measurements, sensor readings, alerts, or clinical advice. The random patient/device assignments, irregular observations and unclear provenance limit use. Do not use these model outputs for care decisions.

## Windows setup (XAMPP)

From PowerShell in `C:\xampp\htdocs\medconnect`:

```powershell
py -3 -m venv ml\.venv
ml\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
pip install -r ml\requirements.txt
python ml\train_model.py
python ml\evaluate_model.py
python -m unittest discover -s ml\tests -v
```

Training creates `ml/models/{heart_rate,spo2,temperature,blood_pressure_sys,blood_pressure_dia}.joblib`, `ml/models/latest.json`, and a versioned JSON evaluation report in `ml/reports/`. Re-running training replaces the current per-target artifacts while keeping prior versioned reports. Do not load joblib artifacts from untrusted sources.

Apply the additive migration once in phpMyAdmin (select the existing `medconnect` database, SQL tab, paste the migration and run). It is idempotent and adds only `ml_predictions`. Before applying it, verify the existing `patients.id` type and InnoDB engine in phpMyAdmin; the migration expects the existing parent key to be `INT` and must not create or modify `doctors`, `patients`, or `health_metrics`. No database credentials are included here. PHP continues to use `includes/db_connection.php` and its existing `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment settings.

Set one shared random service token in the Windows environment used by Apache and the API. For a temporary PowerShell session (replace the generated value in Apache's environment too):

```powershell
$token = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$env:ML_API_TOKEN = $token
$env:ML_API_URL = 'http://127.0.0.1:8001'
```

For XAMPP, configure `SetEnv ML_API_TOKEN "<same token>"` in Apache's `httpd.conf` (or an included local config), then restart Apache. Do not commit the token or put it in a web-accessible file. Start the local service in another PowerShell window with the same token:

```powershell
cd C:\xampp\htdocs\medconnect
$env:ML_API_TOKEN = '<same token configured for Apache>'
ml\.venv\Scripts\python.exe -m uvicorn ml.api:app --host 127.0.0.1 --port 8001 --timeout-keep-alive 5
```

The service binds to loopback. Each PHP prediction request has a two-second HTTP timeout. If it is unavailable, PHP still commits actual readings and simply skips estimates. The PHP form and device endpoint use available heart rate, SpO₂ and temperature measurements as model inputs. Blood pressure is predicted only as an experimental target; missing pressure inputs are never fabricated.

With the API running and the same `ML_API_TOKEN` set in the PHP process, verify PHP transport with `php ml\\tests\\php_api_smoke.php`.

## API examples

Requests require `X-ML-Token`. The trained `heart_rate` model uses `spo2`, `blood_pressure_sys`, `blood_pressure_dia`, and `temperature` (C); it explicitly does not accept `heart_rate` as its own feature.

```powershell
$headers = @{ 'X-ML-Token' = $env:ML_API_TOKEN }
Invoke-RestMethod http://127.0.0.1:8001/health
Invoke-RestMethod -Headers $headers http://127.0.0.1:8001/model-info
Invoke-RestMethod -Method Post -Headers $headers -ContentType 'application/json' `
  -Body '{"target_name":"heart_rate","features":{"spo2":97,"blood_pressure_sys":120,"blood_pressure_dia":80,"temperature":36.8}}' `
  http://127.0.0.1:8001/predict
```

Example response shape (the actual value/version comes from the loaded trained artifact):

```json
{"target_name":"heart_rate","predicted_value":"<value returned by the trained model>","model_version":"<loaded model version>","dataset_version":"<dataset hash>","target_unit":"bpm","prediction_timestamp":"<UTC timestamp>","target_timestamp":"<expected UTC timestamp>","evaluation":{"mae":"<test MAE>","rmse":"<test RMSE>","r2":"<test R2>"},"status":"experimental_estimate","disclaimer":"Estimate for the next observed dataset reading; not a diagnosis or verified sensor measurement."}
```

The sample numeric result above illustrates the response format; use live API output for a prediction. Validation rejects unsupported targets, missing or extra feature fields, out-of-range measurements, and invalid service tokens. `GET /health` reports availability only; `/model-info` is token protected.

## Evaluation

Each training run reports target, selected features, dataset/model versions, selected hyperparameters, train/validation/test counts, test MAE, RMSE and R², plus the persistence baseline's test metrics. Read the exact result from the latest `ml/reports/rf-*.json`; the admin dashboard shows the current run's metrics to authenticated administrators. Results from this Kaggle file are not clinical validation and should not be presented as accuracy claims.

## PHP, authorization and storage

`add-health.php` saves the authenticated patient's real measurement first, then requests predictions best-effort and inserts them in `ml_predictions`. A service outage never rolls back a real reading. Existing patient session ownership is used; clients cannot submit a replacement patient ID through this path. The token-protected device endpoint maps its configured device token to `DEVICE_PATIENT_ID` (or the existing `DEFAULT_PATIENT_ID`) and ignores a patient ID in the request body, so configure a device-specific patient mapping. Doctor estimates are queried only for patients already in the dashboard's doctor/appointment authorization query. Patient estimates are scoped to `$_SESSION['patient_id']`. The model table never updates actual readings. `actual_value` remains null until a later actual reading for that same patient/target falls within 24 hours of the expected target time; that actual is shown separately and can be compared on the patient dashboard graph. Do not generate alerts solely from these estimates.

Database integration and PHP pages require XAMPP's Apache/PDO MySQL and a separately running MySQL service. Tests below exercise Python data and API logic; end-to-end PHP/MySQL/browser checks must be run against the developer's configured local database and XAMPP.

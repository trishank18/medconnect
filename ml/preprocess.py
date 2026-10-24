"""Dataset inspection and leakage-safe next-row target construction."""
from pathlib import Path
import hashlib
import json
import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
DATASET_DIR = ROOT / "dataset"
TARGET_MAP = {
    "heart_rate": ("heart_rate", "bpm"),
    "spo2": ("blood_oxygen", "%"),
    "blood_pressure_sys": ("blood_pressure_systolic", "mmHg"),
    "blood_pressure_dia": ("blood_pressure_diastolic", "mmHg"),
    # Dataset body_temperature values are ~97–99, so this is Fahrenheit; convert to C.
    "temperature": ("body_temperature", "Fahrenheit converted to Celsius"),
}
# Restrict predictors to fields represented in MedConnect's existing health_metrics table.
# Inputs supplied by the current ESP32/MAX30102 ingestion flow and add-health form.
FEATURES = ["heart_rate", "spo2", "temperature"]

def find_dataset():
    files = sorted(DATASET_DIR.glob("*.csv"))
    if len(files) != 1:
        raise ValueError(f"Expected one CSV in {DATASET_DIR}, found {len(files)}: {[p.name for p in files]}")
    return files[0]

def load_dataset(path=None):
    path = Path(path) if path else find_dataset()
    frame = pd.read_csv(path)
    source_columns = list(frame.columns)
    source_dtypes = {k: str(v) for k, v in frame.dtypes.items()}
    required = {"timestamp", "patient_id", "device_id"}
    missing = required - set(frame.columns)
    if missing:
        raise ValueError(f"Dataset missing grouping/time columns: {sorted(missing)}")
    frame["timestamp"] = pd.to_datetime(frame["timestamp"], errors="coerce")
    for source, _ in TARGET_MAP.values():
        if source in frame:
            frame[source] = pd.to_numeric(frame[source], errors="coerce")
    aliases = {"blood_oxygen":"spo2", "blood_pressure_systolic":"blood_pressure_sys",
               "blood_pressure_diastolic":"blood_pressure_dia"}
    for source, target in aliases.items():
        if source in frame: frame[target] = frame[source]
    if "body_temperature" in frame:
        frame["temperature"] = (frame["body_temperature"] - 32) * 5 / 9
    frame.attrs["source_columns"] = source_columns
    frame.attrs["source_dtypes"] = source_dtypes
    return frame, path

def dataset_version(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()[:12]

def report_dataset(frame, path):
    source_columns = frame.attrs.get("source_columns", list(frame.columns))
    source = frame[source_columns]
    result = {"filename": Path(path).name, "rows": int(len(frame)), "columns": frame.attrs.get("source_columns", list(frame.columns)),
        "derived_schema_columns": [c for c in frame.columns if c not in frame.attrs.get("source_columns", frame.columns)],
        "dtypes": frame.attrs.get("source_dtypes", {k: str(v) for k, v in source.dtypes.items()}),
        "missing": {k: int(v) for k, v in source.isna().sum().items()},
        "duplicate_rows": int(source.duplicated().sum()), "sample": source.head(5).where(pd.notna(source.head(5)), None).to_dict("records"),
        "patient_count": int(frame.patient_id.nunique()), "device_count": int(frame.device_id.nunique()),
        "rows_per_patient": frame.groupby("patient_id").size().describe().to_dict(),
        "rows_per_device": frame.groupby("device_id").size().describe().to_dict(),
        "time_range": [str(frame.timestamp.min()), str(frame.timestamp.max())],
        "targets": {target: {"source_column": source, "unit": unit, "present": source in frame.columns}
                    for target, (source, unit) in TARGET_MAP.items()}}
    return result

def construct_next_readings(frame, target):
    if target not in TARGET_MAP:
        raise ValueError(f"Unsupported target: {target}")
    source = TARGET_MAP[target][0]
    if source not in frame:
        raise ValueError(f"Dataset has no source column for {target}")
    data = frame.sort_values(["patient_id", "timestamp"], kind="stable").copy()
    # Patient identity groups rows, never enters predictors. Inputs are current readings;
    # target is the subsequent observed row for the same patient.
    group = data.groupby("patient_id", sort=False)
    data["target"] = group[source].shift(-1)
    data["target_timestamp"] = group.timestamp.shift(-1)
    data["interval_seconds"] = (data.target_timestamp - data.timestamp).dt.total_seconds()
    data["target_patient"] = group.patient_id.shift(-1)
    # Use only plausible nearby subsequent observations, avoiding large temporal gaps.
    data = data[(data.target.notna()) & (data.target_patient == data.patient_id) &
                (data.interval_seconds > 0) & (data.interval_seconds <= 86400)].copy()
    if target == "temperature":
        data["target"] = (data.target - 32) * 5 / 9
    # Source metric is excluded from its own target model. IDs and timestamps excluded.
    features = [f for f in FEATURES if f in data.columns and f != target]
    if not features:
        raise ValueError("No valid predictors after excluding identity, time, and target leakage")
    for f in features:
        data[f] = pd.to_numeric(data[f], errors="coerce")
    bounds = {"heart_rate": (20, 250), "spo2": (50, 100), "blood_pressure_sys": (50, 250),
              "blood_pressure_dia": (30, 150), "temperature": (25, 45)}
    for feature in features:
        if feature in bounds:
            lo_feature, hi_feature = bounds[feature]
            data.loc[~data[feature].between(lo_feature, hi_feature), feature] = float("nan")
    lo, hi = bounds[target]
    data = data[data.target.between(lo, hi)]
    return data, features

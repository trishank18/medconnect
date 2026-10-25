"""Protected FastAPI service for database-backed next-reading estimates."""
import hmac
import logging
import os
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any

import joblib
import pandas as pd
from fastapi import FastAPI, Header, HTTPException, Query

from db import fetch_patient_metrics

BASE = Path(__file__).resolve().parent
MODEL_DIR = BASE / "models"
DEFAULT_HISTORY_LIMIT = 100
MIN_HISTORY = 2
TARGET_BOUNDS = {
    "heart_rate": (20, 250),
    "spo2": (50, 100),
    "temperature": (25, 45),
    "blood_pressure_sys": (50, 250),
    "blood_pressure_dia": (30, 150),
}
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("medconnect.ml")
app = FastAPI(title="MedConnect patient prediction service", version="1")


def authorize(token: str | None) -> None:
    expected = os.getenv("ML_API_TOKEN", "")
    if not expected:
        raise HTTPException(503, "ML_API_TOKEN is not configured")
    if not token or not hmac.compare_digest(token, expected):
        raise HTTPException(401, "Invalid service token")


def load_artifacts() -> dict[str, dict[str, Any]]:
    artifacts = {}
    for path in MODEL_DIR.glob("*.joblib"):
        try:
            artifacts[path.stem] = joblib.load(path)
        except Exception:
            log.exception("Unable to load model artifact target=%s", path.stem)
    return artifacts


def model_metadata(artifacts: dict[str, dict[str, Any]]) -> dict[str, Any]:
    return {
        target: {
            "features": artifact["features"],
            "model_version": artifact["model_version"],
            "target_unit": artifact["target_unit"],
            "metrics": artifact["metrics"],
            "dataset_version": artifact["dataset_version"],
            "objective": artifact["objective"],
        }
        for target, artifact in sorted(artifacts.items())
    }


def validate_measurement(value: Any, name: str) -> float | None:
    if value is None or value == "":
        return None
    try:
        numeric = float(value)
    except (TypeError, ValueError):
        return None
    lo, hi = TARGET_BOUNDS[name]
    return numeric if lo <= numeric <= hi else None


@app.get("/health")
def health() -> dict[str, Any]:
    artifacts = load_artifacts()
    configured = bool(os.getenv("ML_API_TOKEN"))
    return {
        "status": "ok" if artifacts and configured else "service_not_configured",
        "model_version": next(iter(artifacts.values()), {}).get("model_version"),
        "supported_targets": sorted(artifacts),
        "database_configured": bool(os.getenv("DB_NAME", "medconnect")),
    }


@app.get("/model-info")
def model_info(x_ml_token: str | None = Header(default=None)) -> dict[str, Any]:
    authorize(x_ml_token)
    artifacts = load_artifacts()
    return {
        "supported_targets": model_metadata(artifacts),
        "history_requirement": MIN_HISTORY,
        "disclaimer": "Experimental estimates, not diagnoses or verified measurements.",
    }


@app.post("/predict/patient/{patient_id}")
def predict_patient(
    patient_id: int,
    x_ml_token: str | None = Header(default=None),
    history_limit: int = Query(DEFAULT_HISTORY_LIMIT, ge=2, le=500),
) -> dict[str, Any]:
    authorize(x_ml_token)
    if patient_id < 1:
        raise HTTPException(422, "patient_id must be a positive integer")
    artifacts = load_artifacts()
    if not artifacts:
        raise HTTPException(503, "No trained model artifacts are available")
    try:
        records = fetch_patient_metrics(patient_id, history_limit)
    except ValueError as exc:
        raise HTTPException(422, str(exc)) from exc
    except Exception:
        log.exception("Patient metric retrieval failed")
        raise HTTPException(503, "Patient measurements are temporarily unavailable")

    if len(records) < MIN_HISTORY:
        return {
            "patient_id": patient_id,
            "status": "insufficient_history",
            "message": f"At least {MIN_HISTORY} chronological readings are required.",
            "readings_used": len(records),
            "predictions": [],
            "model_version": None,
            "prediction_timestamp": datetime.now(timezone.utc).isoformat(),
        }

    latest = records[-1]
    predictions = []
    skipped = {}
    now = datetime.now(timezone.utc)
    for target, artifact in artifacts.items():
        features = artifact["features"]
        prepared = {name: validate_measurement(latest.get(name), name) for name in features}
        if any(value is None for value in prepared.values()):
            skipped[target] = "latest reading is missing or outside the valid range"
            continue
        try:
            value = float(
                artifact["pipeline"].predict(pd.DataFrame([prepared], columns=features))[0]
            )
        except Exception:
            log.exception("Prediction failed target=%s", target)
            skipped[target] = "model prediction failed"
            continue
        target_timestamp = now + timedelta(
            seconds=float(artifact.get("median_interval_seconds", 0))
        )
        predictions.append({
            "target_name": target,
            "predicted_value": value,
            "target_unit": artifact["target_unit"],
            "model_version": artifact["model_version"],
            "dataset_version": artifact["dataset_version"],
            "evaluation": artifact["metrics"],
            "prediction_timestamp": now.isoformat(),
            "target_timestamp": target_timestamp.isoformat(),
            "features_used": prepared,
        })

    status = "ok" if predictions else "insufficient_valid_data"
    return {
        "patient_id": patient_id,
        "status": status,
        "message": (
            "Experimental next-reading estimates generated from this patient's "
            "latest stored reading."
            if predictions else "No model could use the latest stored reading."
        ),
        "readings_used": len(records),
        "latest_recorded_at": str(latest.get("recorded_at")),
        "predictions": predictions,
        "skipped_targets": skipped,
        "model_version": predictions[0]["model_version"] if predictions else None,
        "prediction_timestamp": now.isoformat(),
        "disclaimer": "Experimental model outputs, not medical diagnoses or verified measurements.",
    }

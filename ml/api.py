"""Local prediction API. Bind to loopback; optionally require ML_API_TOKEN."""
import hmac, json, logging, os
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field
import joblib
import pandas as pd

BASE = Path(__file__).parent
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("medconnect.ml")
app = FastAPI(title="MedConnect experimental next-reading estimates", version="1")

class PredictRequest(BaseModel):
    target_name: str
    features: dict[str, float] = Field(min_length=1)
    target_timestamp: datetime | None = None

def authorize(token):
    expected = os.getenv("ML_API_TOKEN", "")
    if not expected:
        raise HTTPException(503, "ML_API_TOKEN must be configured before using prediction endpoints")
    if expected and not hmac.compare_digest(token or "", expected):
        raise HTTPException(401, "Invalid service token")

def available():
    return {p.stem: joblib.load(p) for p in (BASE / "models").glob("*.joblib")}

@app.get("/health")
def health():
    models = available()
    status = "ok" if models and os.getenv("ML_API_TOKEN") else ("service_not_configured" if models else "models_unavailable")
    return {"status":status, "model_version":next(iter(models.values()))["model_version"] if models else None, "supported_targets":sorted(models)}

@app.get("/model-info")
def model_info(x_ml_token: str | None = Header(default=None)):
    authorize(x_ml_token)
    models=available()
    return {"supported_targets": {k:{"features":v["features"],"version":v["model_version"],"metrics":v["metrics"],"dataset_version":v["dataset_version"],"objective":v["objective"]} for k,v in models.items()},"disclaimer":"Experimental estimates, not diagnoses or verified measurements."}

@app.post("/predict")
def predict(request: PredictRequest, x_ml_token: str | None = Header(default=None)):
    authorize(x_ml_token)
    target=request.target_name
    path=BASE / "models" / f"{target}.joblib"
    if not path.exists(): raise HTTPException(422, "Unsupported or untrained target")
    artifact=joblib.load(path)
    missing=set(artifact["features"])-set(request.features)
    extra=set(request.features)-set(artifact["features"])
    if missing: raise HTTPException(422, {"error":"missing_required_features","fields":sorted(missing)})
    if extra: raise HTTPException(422, {"error":"unexpected_features","fields":sorted(extra)})
    bounds={"heart_rate":(20,250),"spo2":(50,100),"temperature":(25,45),"blood_pressure_sys":(50,250),"blood_pressure_dia":(30,150)}
    for name,value in request.features.items():
        lo,hi=bounds[name]
        if not lo <= value <= hi: raise HTTPException(422,f"{name} outside allowed range")
    try:
        value=float(artifact["pipeline"].predict(pd.DataFrame([{n: request.features[n] for n in artifact["features"]}]))[0])
    except Exception:
        log.exception("Model prediction failed")
        raise HTTPException(503,"Prediction unavailable")
    now=datetime.now(timezone.utc)
    log.info("prediction_generated target=%s model_version=%s",target,artifact["model_version"])
    expected=request.target_timestamp or (now + timedelta(seconds=artifact.get("median_interval_seconds", 0)))
    return {"target_name":target,"predicted_value":value,"model_version":artifact["model_version"],
            "dataset_version":artifact["dataset_version"],"target_unit":artifact["target_unit"],
            "prediction_timestamp":now.isoformat(),"target_timestamp":expected.isoformat(),
            "evaluation":artifact["metrics"],"status":"experimental_estimate",
            "disclaimer":"Estimate for the next observed dataset reading; not a diagnosis or verified sensor measurement."}

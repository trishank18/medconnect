"""Train per-target next-reading Random Forest models on timestamped patient sequences."""
import json, os, sys, tempfile
from datetime import datetime, timezone
from pathlib import Path
import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.pipeline import Pipeline
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import ParameterGrid
import joblib
from preprocess import ROOT, load_dataset, report_dataset, construct_next_readings, dataset_version

MODELS = ROOT / "ml" / "models"
REPORTS = ROOT / "ml" / "reports"

def metrics(y, p):
    return {"mae": round(float(mean_absolute_error(y, p)), 4),
            "rmse": round(float(np.sqrt(mean_squared_error(y, p))), 4),
            "r2": round(float(r2_score(y, p)), 4) if len(y) > 1 else None}

def train(targets=None):
    frame, path = load_dataset()
    MODELS.mkdir(parents=True, exist_ok=True); REPORTS.mkdir(parents=True, exist_ok=True)
    version = datetime.now(timezone.utc).strftime("rf-%Y%m%dT%H%M%SZ")
    report = {"model_version": version, "dataset_version": dataset_version(path),
              "dataset_analysis": report_dataset(frame, path), "objective": "next observed reading for same patient within 24 hours; chronological holdout",
              "models": {}}
    candidates = targets or ["heart_rate", "spo2", "temperature", "blood_pressure_sys", "blood_pressure_dia"]
    supported = {"heart_rate", "spo2", "temperature", "blood_pressure_sys", "blood_pressure_dia"}
    if any(target not in supported for target in candidates):
        raise ValueError("Unsupported model target requested.")
    # Keep working artifacts available until the full training run and report finish.
    staging_context = tempfile.TemporaryDirectory(prefix=".staging-", dir=MODELS)
    staging = Path(staging_context.name)
    for target in candidates:
        try: data, features = construct_next_readings(frame, target)
        except ValueError as exc:
            report["models"][target] = {"status": "unsupported", "reason": str(exc)}; continue
        data = data.sort_values("timestamp", kind="stable").reset_index(drop=True)
        n = len(data)
        if n < 30:
            report["models"][target] = {"status": "unsupported", "reason": f"Only {n} valid time-aligned rows; at least 30 required."}; continue
        a, b = int(n*.70), int(n*.85)
        cut1, cut2 = data.timestamp.iloc[a], data.timestamp.iloc[b]
        # Keep each next-reading label inside its own chronological window.
        train = data[(data.timestamp < cut1) & (data.target_timestamp < cut1)]
        valid = data[(data.timestamp >= cut1) & (data.timestamp < cut2) & (data.target_timestamp < cut2)]
        test = data[data.timestamp >= cut2]
        if min(len(train), len(valid), len(test)) < 20:
            report["models"][target] = {"status":"unsupported", "reason":"Insufficient rows remain after boundary-safe chronological splitting."}; continue
        Xtr, ytr = train[features], train.target
        Xv, yv = valid[features], valid.target
        Xt, yt = test[features], test.target
        # Tune only on chronological validation; final test is untouched.
        best, best_mae, best_params = None, float("inf"), None
        for params in ParameterGrid({"n_estimators": [200, 400], "max_depth": [None, 12], "min_samples_leaf": [1, 3]}):
            candidate = Pipeline([("imputer", SimpleImputer(strategy="median", add_indicator=True)),
                ("model", RandomForestRegressor(random_state=42, n_jobs=-1, **params))])
            candidate.fit(Xtr, ytr)
            score = mean_absolute_error(yv, candidate.predict(Xv))
            if score < best_mae: best, best_mae, best_params = candidate, score, params
        # Refit selected pipeline on train+validation, then evaluate once on test.
        fitted = Pipeline([("imputer", SimpleImputer(strategy="median", add_indicator=True)),
            ("model", RandomForestRegressor(random_state=42, n_jobs=-1, **best_params))])
        fit_rows = pd.concat([train, valid]).sort_values("timestamp", kind="stable")
        fitted.fit(fit_rows[features], fit_rows.target)
        pred = fitted.predict(Xt)
        # Naive persistence baseline: repeat current target measurement, kept outside model features.
        source = {"heart_rate":"heart_rate", "spo2":"blood_oxygen", "temperature":"body_temperature",
                  "blood_pressure_sys":"blood_pressure_systolic", "blood_pressure_dia":"blood_pressure_diastolic"}[target]
        baseline = pd.to_numeric(test[source], errors="coerce").to_numpy()
        if target == "temperature": baseline = (baseline - 32) * 5 / 9
        valid_base = np.isfinite(baseline)
        artifact = {"pipeline": fitted, "target": target, "features": features, "model_version": version,
                    "dataset_version": report["dataset_version"], "target_unit": {"temperature":"C"}.get(target, {"spo2":"%"}.get(target, "bpm" if target == "heart_rate" else "mmHg")),
                    "objective": report["objective"], "metrics": metrics(yt, pred),
                    "median_interval_seconds": float(fit_rows.interval_seconds.median())}
        joblib.dump(artifact, staging / f"{target}.joblib")
        report["models"][target] = {"status":"trained", "features":features, "target":target,
            "train_records":len(train), "validation_records":len(valid), "test_records":len(test),
            "validation_mae":round(float(best_mae),4), "test":metrics(yt,pred), "baseline_persistence_test":metrics(yt[valid_base],baseline[valid_base]),
            "hyperparameters":best_params, "target_interval_seconds":{"median":float(data.interval_seconds.median()), "max":86400},
            "artifact":f"models/{target}.joblib"}
    report_file = REPORTS / f"{version}.json"
    report_file.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    # Publish only after a successful full pass. Unsupported selected targets no
    # longer serve stale models; an interrupted training run leaves old artifacts intact.
    for target in candidates:
        staged_artifact = staging / f"{target}.joblib"
        final_artifact = MODELS / f"{target}.joblib"
        if staged_artifact.is_file():
            os.replace(staged_artifact, final_artifact)
        else:
            final_artifact.unlink(missing_ok=True)
    latest = MODELS / "latest.json"
    latest_temp = MODELS / f".latest-{version}.tmp"
    latest_temp.write_text(json.dumps({"model_version":version,"report":str(report_file.relative_to(ROOT / 'ml'))},indent=2),encoding="utf-8")
    os.replace(latest_temp, latest)
    staging_context.cleanup()
    print(json.dumps(report, indent=2, default=str))
    return report

if __name__ == "__main__":
    train(sys.argv[1:] or None)

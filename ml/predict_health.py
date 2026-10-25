import json
import math
import sys


def respond(payload):
    print(json.dumps(payload))


def main():
    try:
        from sklearn.ensemble import IsolationForest
        from sklearn.preprocessing import StandardScaler
    except ImportError:
        respond({
            "success": False,
            "status": "unavailable",
            "message": "The ML dependency is not installed. Run: py -m pip install -r requirements.txt"
        })
        return

    try:
        records = json.load(sys.stdin)
        feature_names = [
            "heart_rate",
            "spo2",
            "temperature",
            "blood_pressure_sys",
            "blood_pressure_dia",
        ]
        usable = []
        for record in records:
            if not all(record.get(name) is not None for name in feature_names):
                continue
            values = [float(record[name]) for name in feature_names]
            if all(math.isfinite(value) for value in values):
                usable.append(values)
    except (ValueError, TypeError, KeyError, json.JSONDecodeError):
        respond({"success": False, "status": "error", "message": "Invalid health metric data."})
        return

    if len(usable) < 5:
        respond({
            "success": True,
            "status": "insufficient_data",
            "message": "At least 5 complete readings are needed before the model can screen for anomalies.",
            "readings_used": len(usable)
        })
        return

    # Do not silently screen an older complete record as if it were the latest
    # when the newest stored reading is incomplete.
    latest = records[-1] if records else None
    latest_is_complete = bool(latest) and all(latest.get(name) is not None for name in feature_names)
    latest_is_finite = False
    if latest_is_complete:
        try:
            latest_is_finite = all(math.isfinite(float(latest[name])) for name in feature_names)
        except (ValueError, TypeError):
            latest_is_finite = False
    if not latest_is_finite:
        respond({
            "success": True,
            "status": "insufficient_data",
            "message": "The most recent stored reading is incomplete, so no pattern comparison is shown.",
            "readings_used": len(usable)
        })
        return

    try:
        scaled = StandardScaler().fit_transform(usable)
        model = IsolationForest(
            n_estimators=100,
            contamination="auto",
            random_state=42
        )
        model.fit(scaled)
        prediction = int(model.predict([scaled[-1]])[0])
        raw_score = float(model.decision_function([scaled[-1]])[0])
        if prediction == -1:
            level = "attention"
            message = "The latest reading differs from recent measurements. This screen cannot determine its medical significance."
        elif raw_score < 0.25:
            level = "monitor"
            message = "The latest reading varies from recent measurements. This screen cannot determine its medical significance."
        else:
            level = "normal"
            message = "The latest reading is consistent with this patient's recent pattern."

        respond({
            "success": True,
            "status": level,
            "message": message,
            "readings_used": len(usable),
            "disclaimer": "This is an anomaly screen, not a diagnosis."
        })
    except (ValueError, RuntimeError):
        respond({"success": False, "status": "error", "message": "The experimental pattern analysis could not be completed."})


if __name__ == "__main__":
    main()

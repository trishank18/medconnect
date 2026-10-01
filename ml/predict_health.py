import json
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
        usable = [
            [float(record[name]) for name in feature_names]
            for record in records
            if all(record.get(name) is not None for name in feature_names)
        ]
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
        anomaly_score = max(0.0, min(100.0, round((0.5 - raw_score) * 100, 1)))

        if prediction == -1:
            level = "attention"
            message = "The latest reading differs from this patient's recent pattern. Consider clinical review."
        elif anomaly_score >= 25:
            level = "monitor"
            message = "The latest reading is somewhat different from this patient's recent pattern."
        else:
            level = "normal"
            message = "The latest reading is consistent with this patient's recent pattern."

        respond({
            "success": True,
            "status": level,
            "message": message,
            "anomaly_score": anomaly_score,
            "readings_used": len(usable),
            "disclaimer": "This is an anomaly screen, not a diagnosis."
        })
    except (ValueError, RuntimeError) as error:
        respond({"success": False, "status": "error", "message": str(error)})


if __name__ == "__main__":
    main()

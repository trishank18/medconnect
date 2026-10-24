"""Load the saved training pipeline and predict one explicitly supplied target."""
import argparse, json
from pathlib import Path
import joblib
import pandas as pd

def predict(target, features):
    path = Path(__file__).parent / "models" / f"{target}.joblib"
    if not path.exists(): raise FileNotFoundError(f"No trained model for target {target}")
    artifact = joblib.load(path)
    missing = set(artifact["features"]) - set(features)
    if missing: raise ValueError(f"Missing model inputs: {sorted(missing)}")
    value = float(artifact["pipeline"].predict(pd.DataFrame([{n: features[n] for n in artifact["features"]}]))[0])
    return {"target_name":target,"predicted_value":value,"model_version":artifact["model_version"],
            "target_unit":artifact["target_unit"],"features":artifact["features"],"evaluation":artifact["metrics"],
            "disclaimer":"Experimental estimate of the next observed dataset reading; not a diagnosis or verified sensor reading."}

if __name__ == "__main__":
    p=argparse.ArgumentParser(); p.add_argument("target"); p.add_argument("features",help="JSON object"); a=p.parse_args()
    print(json.dumps(predict(a.target,json.loads(a.features))))

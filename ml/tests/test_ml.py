import os, sys, unittest
from pathlib import Path
from fastapi.testclient import TestClient

ML_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ML_DIR))
from preprocess import load_dataset, construct_next_readings
from api import app

class DatasetAndApiTests(unittest.TestCase):
    def test_csv_schema_and_sequence_targets(self):
        frame, path = load_dataset()
        self.assertTrue(path.is_file())
        self.assertGreater(len(frame), 1000)
        self.assertTrue({"patient_id", "device_id", "timestamp", "blood_oxygen"}.issubset(frame.columns))
        rows, features = construct_next_readings(frame, "heart_rate")
        self.assertGreater(len(rows), 100)
        self.assertNotIn("heart_rate", features)
        self.assertNotIn("patient_id", features)
        self.assertTrue((rows.interval_seconds > 0).all())
        self.assertTrue((rows.interval_seconds <= 86400).all())

    def test_prediction_validation_and_service_auth(self):
        os.environ["ML_API_TOKEN"] = "unit-test-token"
        client = TestClient(app)
        headers = {"X-ML-Token": "unit-test-token"}
        missing = client.post("/predict", headers=headers, json={"target_name":"heart_rate", "features":{"spo2":98}})
        self.assertEqual(missing.status_code, 422)
        bad = client.post("/predict", headers=headers, json={"target_name":"heart_rate", "features":{"spo2":101,"temperature":37}})
        self.assertEqual(bad.status_code, 422)
        unauthorized = client.get("/model-info", headers={"X-ML-Token":"bad"})
        self.assertEqual(unauthorized.status_code, 401)
        good = client.post("/predict", headers=headers, json={"target_name":"heart_rate", "features":{"spo2":98,"temperature":37}})
        self.assertEqual(good.status_code, 200, good.text)
        self.assertIn("predicted_value", good.json())
        self.assertIn("model_version", good.json())

if __name__ == "__main__": unittest.main()

import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from fastapi.testclient import TestClient

ML_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ML_DIR))
from api import app
from preprocess import construct_next_readings, load_dataset


class DatasetAndApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ["ML_API_TOKEN"] = "unit-test-token"
        cls.client = TestClient(app)

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

    def test_patient_endpoint_uses_only_requested_patient_records(self):
        records = [
            {"heart_rate": 70, "spo2": 98, "temperature": 36.7, "blood_pressure_sys": 120,
             "blood_pressure_dia": 80, "recorded_at": "2026-01-01 10:00:00"},
            {"heart_rate": 72, "spo2": 97, "temperature": 36.8, "blood_pressure_sys": 121,
             "blood_pressure_dia": 81, "recorded_at": "2026-01-01 10:10:00"},
        ]
        with patch("api.fetch_patient_metrics", return_value=records) as fetch:
            response = self.client.post("/predict/patient/7", headers={"X-ML-Token": "unit-test-token"})
        self.assertEqual(response.status_code, 200, response.text)
        self.assertEqual(response.json()["patient_id"], 7)
        fetch.assert_called_once_with(7, 100)
        self.assertTrue(response.json()["predictions"])
        self.assertEqual(response.json()["predictions"][0]["features_used"]["spo2"], 97.0)

    def test_insufficient_history_is_explicit(self):
        with patch("api.fetch_patient_metrics", return_value=[]):
            response = self.client.post("/predict/patient/7", headers={"X-ML-Token": "unit-test-token"})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["status"], "insufficient_history")
        self.assertEqual(response.json()["predictions"], [])

    def test_patient_endpoint_requires_service_token(self):
        response = self.client.post("/predict/patient/7", headers={"X-ML-Token": "wrong"})
        self.assertEqual(response.status_code, 401)

    def test_old_manual_feature_endpoint_is_not_available(self):
        response = self.client.post("/predict", headers={"X-ML-Token": "unit-test-token"})
        self.assertEqual(response.status_code, 404)


if __name__ == "__main__":
    unittest.main()

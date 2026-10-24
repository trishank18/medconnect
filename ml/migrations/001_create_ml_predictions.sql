-- Safe to re-run. Predictions are separate from health_metrics.
CREATE TABLE IF NOT EXISTS ml_predictions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT NOT NULL,
    target_name VARCHAR(64) NOT NULL,
    predicted_value DECIMAL(12,4) NOT NULL,
    actual_value DECIMAL(12,4) NULL,
    model_version VARCHAR(80) NOT NULL,
    prediction_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    target_timestamp DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ml_predictions_patient_time (patient_id, prediction_timestamp),
    KEY idx_ml_predictions_target_time (patient_id, target_name, target_timestamp),
    CONSTRAINT fk_ml_predictions_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

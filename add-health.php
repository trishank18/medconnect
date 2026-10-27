<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['patient_id'])) {
    header('Location: patient-login.html');
    exit();
}
$_SESSION['patient_csrf'] ??= bin2hex(random_bytes(32));

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/ml_client.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['patient_csrf'], (string) ($_POST['patient_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
    $values = [
        'heart_rate' => filter_input(INPUT_POST, 'heart_rate', FILTER_VALIDATE_INT),
        'spo2' => filter_input(INPUT_POST, 'spo2', FILTER_VALIDATE_INT),
        'temperature' => filter_input(INPUT_POST, 'temperature', FILTER_VALIDATE_FLOAT),
        'blood_pressure_sys' => filter_input(INPUT_POST, 'blood_pressure_sys', FILTER_VALIDATE_INT),
        'blood_pressure_dia' => filter_input(INPUT_POST, 'blood_pressure_dia', FILTER_VALIDATE_INT)
    ];

    $bounds = [
        'heart_rate' => [20, 250],
        'spo2' => [50, 100],
        'temperature' => [25, 45],
        'blood_pressure_sys' => [50, 250],
        'blood_pressure_dia' => [30, 150],
    ];
    $valid = true;
    foreach ($bounds as $field => [$minimum, $maximum]) {
        $value = $values[$field];
        if ($value === false || $value === null || $value < $minimum || $value > $maximum) {
            $valid = false;
            break;
        }
    }
    if ($valid && $values['blood_pressure_sys'] <= $values['blood_pressure_dia']) {
        $valid = false;
    }

    if (!$valid) {
        $error = 'Please enter valid values for every measurement.';
    } else {
        try {
            $stmt = $conn->prepare(
                'INSERT INTO health_metrics
                    (patient_id, heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia)
                 VALUES (:patient_id, :heart_rate, :spo2, :temperature, :blood_pressure_sys, :blood_pressure_dia)'
            );
            $stmt->execute([
                ':patient_id' => $_SESSION['patient_id'],
                ':heart_rate' => $values['heart_rate'],
                ':spo2' => $values['spo2'],
                ':temperature' => $values['temperature'],
                ':blood_pressure_sys' => $values['blood_pressure_sys'],
                ':blood_pressure_dia' => $values['blood_pressure_dia']
            ]);

            // Attach an observed future value only to due predictions for this patient and target.
            try {
                $reconcile = $conn->prepare("UPDATE ml_predictions SET actual_value = :actual_value
                    WHERE patient_id = :patient_id AND target_name = :target_name AND actual_value IS NULL
                      AND target_timestamp <= NOW() AND target_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                foreach ($values as $target => $actualValue) {
                    $reconcile->execute([':actual_value' => $actualValue, ':patient_id' => $_SESSION['patient_id'], ':target_name' => $target]);
                }
            } catch (PDOException $ignored) {
                // Prediction reconciliation is optional; preserve actual reading ingestion on failure.
            }

            // Actual measurement is committed first. Predictions are database-backed
            // and best-effort; an unavailable model never undoes the reading.
            try {
                $predictionResponse = request_patient_predictions((int) $_SESSION['patient_id']);
                if ($predictionResponse['ok']) {
                    store_patient_predictions(
                        $conn,
                        (int) $_SESSION['patient_id'],
                        $predictionResponse['data']
                    );
                }
            } catch (Throwable $ignored) {
                // Prediction persistence must not undo an actual health reading.
            }
            header('Location: patient-dashboard.php?health_saved=1');
            exit();
        } catch (PDOException $exception) {
            $error = 'Unable to save health data right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Health Data - MedConnect</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/business.css">
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 760px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-heart-pulse"></i> Add Health Data</h2>
        <a href="patient-dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <p class="text-muted">Enter a complete reading to include it in your health history and personal pattern screen.</p>
            <form method="POST" class="row g-3">
                <input type="hidden" name="patient_csrf" value="<?= htmlspecialchars($_SESSION['patient_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-6">
                    <label for="heart_rate" class="form-label">Heart rate (BPM)</label>
                    <input type="number" class="form-control" id="heart_rate" name="heart_rate" min="20" max="250" required>
                </div>
                <div class="col-md-6">
                    <label for="spo2" class="form-label">SpO2 (%)</label>
                    <input type="number" class="form-control" id="spo2" name="spo2" min="50" max="100" required>
                </div>
                <div class="col-md-4">
                    <label for="temperature" class="form-label">Temperature (C)</label>
                    <input type="number" class="form-control" id="temperature" name="temperature" min="25" max="45" step="0.1" required>
                </div>
                <div class="col-md-4">
                    <label for="blood_pressure_sys" class="form-label">Systolic (mmHg)</label>
                    <input type="number" class="form-control" id="blood_pressure_sys" name="blood_pressure_sys" min="50" max="250" required>
                </div>
                <div class="col-md-4">
                    <label for="blood_pressure_dia" class="form-label">Diastolic (mmHg)</label>
                    <input type="number" class="form-control" id="blood_pressure_dia" name="blood_pressure_dia" min="30" max="150" required>
                </div>
                <div class="col-12 d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Save Health Data</button>
                    <a href="patient-dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="js/design-switch.js?v=2"></script>
</body>
</html>

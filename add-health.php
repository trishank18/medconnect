<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header('Location: patient-login.html');
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'heart_rate' => filter_input(INPUT_POST, 'heart_rate', FILTER_VALIDATE_INT),
        'spo2' => filter_input(INPUT_POST, 'spo2', FILTER_VALIDATE_INT),
        'temperature' => filter_input(INPUT_POST, 'temperature', FILTER_VALIDATE_FLOAT),
        'blood_pressure_sys' => filter_input(INPUT_POST, 'blood_pressure_sys', FILTER_VALIDATE_INT),
        'blood_pressure_dia' => filter_input(INPUT_POST, 'blood_pressure_dia', FILTER_VALIDATE_INT)
    ];

    if ($values['heart_rate'] === false || $values['spo2'] === false || $values['temperature'] === false
        || $values['blood_pressure_sys'] === false || $values['blood_pressure_dia'] === false) {
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
<script src="js/design-switch.js"></script>
</body>
</html>

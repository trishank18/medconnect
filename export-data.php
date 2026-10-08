<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

if (PHP_SAPI !== 'cli' && empty($_SESSION['admin_authenticated'])) {
    header('Location: admin-login.html');
    exit();
}

$exports = [
    'doctors' => 'SELECT id, fullname, email, phone, department, username, verification_status, created_at FROM doctors ORDER BY id',
    'patients' => 'SELECT id, fullname, email, phone, age, username, doctor_id, created_at FROM patients ORDER BY id',
    'appointments' => 'SELECT id, patient_id, doctor_id, appointment_date, appointment_time, status, notes, created_at FROM appointments ORDER BY id',
    'health_metrics' => 'SELECT id, patient_id, heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at FROM health_metrics ORDER BY id',
    'patient_suggestions' => 'SELECT id, doctor_id, patient_id, appointment_id, suggestion, created_at FROM patient_suggestions ORDER BY id',
    'patient_prescriptions' => 'SELECT id, doctor_id, patient_id, appointment_id, prescription, medication, dosage, instructions, created_at FROM patient_prescriptions ORDER BY id',
];

$exportDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'exports';

try {
    if (!is_dir($exportDirectory) && !mkdir($exportDirectory, 0750, true) && !is_dir($exportDirectory)) {
        throw new RuntimeException('The export directory could not be created.');
    }

    foreach ($exports as $filename => $query) {
        $temporaryPath = $exportDirectory . DIRECTORY_SEPARATOR . $filename . '.csv.tmp';
        $finalPath = $exportDirectory . DIRECTORY_SEPARATOR . $filename . '.csv';
        $handle = fopen($temporaryPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("The {$filename} CSV file could not be opened.");
        }

        try {
            $statement = $conn->query($query);
            $columns = [];
            for ($column = 0; $column < $statement->columnCount(); $column++) {
                $columns[] = $statement->getColumnMeta($column)['name'];
            }
            fputcsv($handle, $columns);

            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }

        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException("The {$filename} CSV file could not be saved.");
        }
    }
} catch (Throwable $exception) {
    if (isset($temporaryPath) && is_file($temporaryPath)) {
        unlink($temporaryPath);
    }
    http_response_code(500);
    echo 'CSV export failed. Please check the server error log.';
    exit();
}

header('Location: admin-dashboard.php?exported=1');
exit();

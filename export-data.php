<?php
require_once __DIR__ . '/includes/session.php';
if (!medconnect_role_authenticated('admin')) {
    header('Location: admin-login.html');
    exit();
}

$exports = [
    'doctors' => [
        'filename' => 'doctors.csv',
        'query' => 'SELECT id, fullname, email, phone, department, username, verification_status, created_at FROM doctors ORDER BY id',
    ],
    'patients' => [
        'filename' => 'patients.csv',
        'query' => 'SELECT id, fullname, email, phone, age, username, doctor_id, created_at FROM patients ORDER BY id',
    ],
    'appointments' => [
        'filename' => 'appointments.csv',
        'query' => 'SELECT id, patient_id, doctor_id, appointment_date, appointment_time, status, notes, created_at FROM appointments ORDER BY id',
    ],
    'health_metrics' => [
        'filename' => 'health_metrics.csv',
        'query' => 'SELECT id, patient_id, heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at FROM health_metrics ORDER BY id',
    ],
    'patient_suggestions' => [
        'filename' => 'patient_suggestions.csv',
        'query' => 'SELECT id, doctor_id, patient_id, appointment_id, suggestion, created_at FROM patient_suggestions ORDER BY id',
    ],
    'patient_prescriptions' => [
        'filename' => 'patient_prescriptions.csv',
        'query' => 'SELECT id, doctor_id, patient_id, appointment_id, prescription, medication, dosage, instructions, created_at FROM patient_prescriptions ORDER BY id',
    ],
];

$table = (string) ($_GET['table'] ?? '');
if (!isset($exports[$table])) {
    http_response_code(404);
    exit('Choose a valid export from the administrator dashboard.');
}

require_once __DIR__ . '/includes/db_connection.php';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $exports[$table]['filename'] . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('The export could not be started.');
}

try {
    $statement = $conn->query($exports[$table]['query']);
    $columnCount = $statement->columnCount();
    $headers = [];
    for ($column = 0; $column < $columnCount; $column++) {
        $headers[] = $statement->getColumnMeta($column)['name'];
    }
    fputcsv($output, $headers, ',', '"', '');

    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
        $safeRow = array_map(static function ($value) {
            if (!is_string($value)) {
                return $value;
            }
            if (!is_numeric($value) && preg_match('/^[\x00-\x20]*[=+@-]/', $value)) {
                return "'" . $value;
            }
            return $value;
        }, $row);
        fputcsv($output, $safeRow, ',', '"', '');
    }
} catch (Throwable $exception) {
    error_log('MedConnect CSV export failed: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
} finally {
    fclose($output);
}

<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

require_once __DIR__ . '/includes/db_connection.php';
$prescriptionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$prescriptionId) {
    http_response_code(400);
    exit('Invalid prescription.');
}

$stmt = $conn->prepare(
    'SELECT pp.medication, pp.dosage, pp.instructions, pp.prescription, pp.created_at,
            d.fullname AS doctor_name, d.department, p.fullname AS patient_name
     FROM patient_prescriptions pp
     INNER JOIN doctors d ON d.id = pp.doctor_id
     INNER JOIN patients p ON p.id = pp.patient_id
     WHERE pp.id = :id AND pp.patient_id = :patient_id'
);
$stmt->execute([
    ':id' => $prescriptionId,
    ':patient_id' => $_SESSION['patient_id']
]);
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    http_response_code(404);
    exit('Prescription not found.');
}

$filename = 'prescription-' . $prescriptionId . '.html';
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Prescription <?= htmlspecialchars((string) $prescriptionId) ?></title>
    <style>
        body { font-family: Arial, sans-serif; color: #222; max-width: 760px; margin: 40px auto; }
        h1 { color: #0d6efd; }
        .meta { border-bottom: 1px solid #ddd; padding-bottom: 16px; }
        .section { margin-top: 24px; }
        .label { font-weight: bold; }
    </style>
</head>
<body>
    <h1>MedConnect Prescription</h1>
    <div class="meta">
        <p><span class="label">Patient:</span> <?= htmlspecialchars($prescription['patient_name']) ?></p>
        <p><span class="label">Doctor:</span> <?= htmlspecialchars($prescription['doctor_name']) ?> (<?= htmlspecialchars($prescription['department']) ?>)</p>
        <p><span class="label">Issued:</span> <?= htmlspecialchars(date('d M Y, h:i A', strtotime($prescription['created_at']))) ?></p>
    </div>
    <div class="section">
        <p><span class="label">Medication:</span> <?= htmlspecialchars($prescription['medication'] ?: 'N/A') ?></p>
        <p><span class="label">Dosage:</span> <?= htmlspecialchars($prescription['dosage'] ?: 'N/A') ?></p>
        <p><span class="label">Instructions:</span><br><?= nl2br(htmlspecialchars($prescription['instructions'] ?: 'N/A')) ?></p>
        <p><span class="label">Prescription notes:</span><br><?= nl2br(htmlspecialchars($prescription['prescription'])) ?></p>
    </div>
<script src="js/design-switch.js"></script>
</body>
</html>

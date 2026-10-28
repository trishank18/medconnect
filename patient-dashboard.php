<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/assistant_session.php';
if (!medconnect_role_authenticated('patient')) {
    header("Location: patient-login.html");
    exit();
}
$_SESSION['patient_csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['patient_csrf'], (string) ($_POST['patient_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request token. Reload the dashboard and try again.');
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/ml_client.php';

try {
    // Fetch patient profile
    $stmt = $conn->prepare("SELECT id, fullname, email, phone, age, username, doctor_id, created_at FROM patients WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['auth']['patient']['id']);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        medconnect_clear_role_auth('patient');
        header("Location: patient-login.html?error=patient_not_found");
        exit();
    }

    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $fullname = trim((string) ($_POST['fullname'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $age = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT);
        if ($fullname === '' || strlen($fullname) > 100 || preg_match('/[\r\n]/', $fullname)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
            || !preg_match('/^[+0-9(). -]{7,20}$/', $phone)
            || $age === false || $age < 1 || $age > 150) {
            header('Location: patient-dashboard.php?error=invalid_profile#updateProfileModal');
            exit();
        }
        $duplicateEmail = $conn->prepare('SELECT 1 FROM patients WHERE email = :email AND id <> :id LIMIT 1');
        $duplicateEmail->execute([':email' => $email, ':id' => (int) $_SESSION['auth']['patient']['id']]);
        if ($duplicateEmail->fetchColumn()) {
            header('Location: patient-dashboard.php?error=duplicate_email#updateProfileModal');
            exit();
        }

        $updateStmt = $conn->prepare("UPDATE patients SET fullname = :fullname, email = :email, phone = :phone, age = :age WHERE id = :id");
        $updateStmt->bindParam(':fullname', $fullname);
        $updateStmt->bindParam(':email', $email);
        $updateStmt->bindParam(':phone', $phone);
        $updateStmt->bindParam(':age', $age);
        $updateStmt->bindParam(':id', $_SESSION['auth']['patient']['id']);
        $updateStmt->execute();

        header('Location: patient-dashboard.php?profile_updated=1');
        exit();
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if ($appointmentId) {
            $cancelStmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = :id AND patient_id = :patient_id AND status = 'pending'");
            $cancelStmt->execute([
                ':id' => $appointmentId,
                ':patient_id' => $_SESSION['auth']['patient']['id']
            ]);
        }

        header("Location: patient-dashboard.php?appointment_cancelled=1#appointments");
        exit();
    }

    // Fetch latest health metrics
    $stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->bindParam(':patient_id', $_SESSION['auth']['patient']['id']);
    $stmt->execute();
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    $mlHealthContext = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $mlHealth = @file_get_contents(rtrim(getenv('ML_API_URL') ?: 'http://127.0.0.1:8001', '/') . '/health', false, $mlHealthContext);
    $mlHealthData = is_string($mlHealth) ? json_decode($mlHealth, true) : null;
    $mlServiceAvailable = is_array($mlHealthData) && ($mlHealthData['status'] ?? '') === 'ok';

    // Fetch the complete history so the patient can review every recorded reading.
    $history_stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        ORDER BY recorded_at DESC, id DESC
    ");
    $history_stmt->bindParam(':patient_id', $_SESSION['auth']['patient']['id']);
    $history_stmt->execute();
    $metrics_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    $mlPredictionError = null;
    try {
        $predictionResponse = request_patient_predictions((int) $_SESSION['auth']['patient']['id']);
        if ($predictionResponse['ok']) {
            store_patient_predictions(
                $conn,
                (int) $_SESSION['auth']['patient']['id'],
                $predictionResponse['data']
            );
        } else {
            $mlPredictionError = $predictionResponse['error'];
        }
    } catch (Throwable $ignored) {
        $mlPredictionError = 'Predictions are temporarily unavailable.';
    }

    $mlPredictions = [];
    try {
        $predictionStmt = $conn->prepare("SELECT target_name, predicted_value, actual_value, model_version, prediction_timestamp, target_timestamp
            FROM ml_predictions WHERE patient_id = :patient_id ORDER BY prediction_timestamp DESC LIMIT 10");
        $predictionStmt->execute([':patient_id' => $_SESSION['auth']['patient']['id']]);
        $mlPredictions = $predictionStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ignored) {
        // Keep the core dashboard usable until the optional predictions migration is applied.
    }
    $heartRateComparisons = array_reverse(array_values(array_filter($mlPredictions, static fn($row) =>
        $row['target_name'] === 'heart_rate' && $row['actual_value'] !== null)));

    // Prepare data for Chart.js in chronological order.
    $chart_labels = [];
    $heart_rate_data = [];
    $spo2_data = [];
    $temperature_data = [];
    $blood_pressure_sys_data = [];
    $blood_pressure_dia_data = [];

    foreach (array_reverse($metrics_history) as $record) {
        $chart_labels[] = date("M j, H:i", strtotime($record['recorded_at']));
        $heart_rate_data[] = $record['heart_rate'];
        $spo2_data[] = $record['spo2'];
        $temperature_data[] = $record['temperature'];
        $blood_pressure_sys_data[] = $record['blood_pressure_sys'];
        $blood_pressure_dia_data[] = $record['blood_pressure_dia'];
    }

    // Fetch doctor list
    $doctors = $conn->query("SELECT id, fullname, department, verification_status FROM doctors WHERE verification_status = 'approved' ORDER BY department, fullname")->fetchAll(PDO::FETCH_ASSOC);
    $doctors_by_department = [];
    foreach ($doctors as $doctor) {
        $department = trim((string) ($doctor['department'] ?? 'General')) ?: 'General';
        $doctors_by_department[$department][] = $doctor;
    }

    // Fetch patient appointments
    $appt_stmt = $conn->prepare("
        SELECT a.*, d.fullname AS doctor_name, d.department AS doctor_department, d.verification_status AS doctor_verification_status
        FROM appointments a 
        JOIN doctors d ON a.doctor_id = d.id 
        WHERE a.patient_id = :patient_id 
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $appt_stmt->bindParam(':patient_id', $_SESSION['auth']['patient']['id']);
    $appt_stmt->execute();
    $appointments = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch prescriptions from patient_prescriptions
    $presc_stmt = $conn->prepare("
        SELECT pp.*, d.fullname AS doctor_name, d.verification_status AS doctor_verification_status
        FROM patient_prescriptions pp
        JOIN doctors d ON pp.doctor_id = d.id
        WHERE pp.patient_id = :patient_id
        ORDER BY pp.created_at DESC
    ");
    $presc_stmt->bindParam(':patient_id', $_SESSION['auth']['patient']['id']);
    $presc_stmt->execute();
    $prescriptions = $presc_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Suggestions are visible only to the patient who owns the authenticated session.
    $suggestionStmt = $conn->prepare("SELECT ps.suggestion, ps.created_at, d.fullname AS doctor_name, d.department,
            a.appointment_date, a.appointment_time
        FROM patient_suggestions ps
        INNER JOIN doctors d ON d.id = ps.doctor_id
        INNER JOIN appointments a ON a.id = ps.appointment_id
        WHERE ps.patient_id = :patient_id
        ORDER BY ps.created_at DESC, ps.id DESC
        LIMIT 50");
    $suggestionStmt->execute([':patient_id' => (int) $_SESSION['auth']['patient']['id']]);
    $doctorSuggestions = $suggestionStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('MedConnect patient dashboard query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The patient workspace is temporarily unavailable. Please try again later.');
}
$pendingAppointments = count(array_filter($appointments, static fn($appointment) => $appointment['status'] === 'pending'));
$completedAppointments = count(array_filter($appointments, static fn($appointment) => $appointment['status'] === 'completed'));
$upcomingAppointment = null;
foreach ($appointments as $appointment) {
    $appointmentTimestamp = strtotime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
    if ($appointment['status'] === 'pending' && $appointmentTimestamp >= time() &&
        (!$upcomingAppointment || $appointmentTimestamp < strtotime($upcomingAppointment['appointment_date'] . ' ' . $upcomingAppointment['appointment_time']))) {
        $upcomingAppointment = $appointment;
    }
}
$patientDoctor = null;
foreach ($doctors as $availableDoctor) {
    if ((int) $availableDoctor['id'] === (int) ($patient['doctor_id'] ?? 0)) {
        $patientDoctor = $availableDoctor;
        break;
    }
}
if (!$patientDoctor && $upcomingAppointment && ($upcomingAppointment['doctor_verification_status'] ?? '') === 'approved') {
    $patientDoctor = ['fullname' => $upcomingAppointment['doctor_name'], 'department' => $upcomingAppointment['doctor_department']];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/business.css">
    <link rel="stylesheet" href="css/dashboard-modern.css?v=20261025-10">
    <link rel="stylesheet" href="css/health-assistant.css">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="js/theme-init.js"></script>
    <script src="js/dashboard-shell.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
</head>
<body class="bg-light modern-dashboard patient-modern-dashboard" data-user-name="<?= htmlspecialchars($patient['fullname'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="Patient">
<div class="container py-5">
    <!-- Updated Header with Profile Dropdown -->
    <div class="patient-dashboard-header mb-4">
        <div>
            <p class="dashboard-eyebrow mb-1"><i class="bi bi-heart-pulse"></i> Patient portal</p>
            <h2 class="mb-0">Welcome, <?= htmlspecialchars($patient['fullname']) ?></h2>
        </div>
        <div class="patient-action-toolbar" aria-label="Patient dashboard actions">
            <a href="#book-appointment" class="btn btn-primary patient-primary-action">
                <i class="bi bi-calendar2-plus"></i> Book appointment
            </a>
            <div class="patient-action-group">
                <a href="patient-prediction.php" class="btn btn-outline-primary">
                    <i class="bi bi-robot"></i> AI Analytics
                </a>
                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#healthTrendsModal">
                    <i class="bi bi-graph-up"></i> Trends
                </button>
                <a href="#prescriptions" class="btn btn-outline-success">
                    <i class="bi bi-prescription2"></i> Prescriptions
                </a>
            </div>

            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars(explode(' ', $patient['fullname'])[0]) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#viewProfileModal">
                        <i class="bi bi-eye"></i> View Profile
                    </a></li>
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                        <i class="bi bi-pencil-square"></i> Edit Profile
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php?role=patient">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <section class="modern-overview patient-overview" aria-labelledby="patient-overview-title">
        <div class="modern-overview-header">
            <div>
                <p class="modern-kicker">Patient workspace</p>
                <h1 id="patient-overview-title">Good <?= date('G') < 12 ? 'morning' : (date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars(explode(' ', trim($patient['fullname']))[0]) ?> <span aria-hidden="true">✦</span></h1>
                <p><?= date('l, F j, Y') ?> · Your personal health overview</p>
            </div>
            <span class="monitoring-pill"><span></span><?= !empty($metrics['recorded_at']) ? 'Readings available' : 'Awaiting readings' ?></span>
        </div>
        <?php if (isset($_GET['health_saved']) || isset($_GET['success']) || isset($_GET['appointment_cancelled']) || isset($_GET['profile_updated']) || isset($_GET['error'])): ?>
          <div class="alert <?= isset($_GET['error']) ? 'alert-warning' : 'alert-success' ?>" role="status">
            <?php if (isset($_GET['health_saved'])): ?>Health reading saved successfully.
            <?php elseif (isset($_GET['appointment_cancelled'])): ?>Appointment cancelled successfully.
            <?php elseif (isset($_GET['profile_updated'])): ?>Profile updated successfully.
            <?php elseif ($_GET['error'] === 'invalid_profile'): ?>Check the profile fields and try again.
            <?php elseif ($_GET['error'] === 'duplicate_email'): ?>That email is already used by another account.
            <?php elseif (isset($_GET['success'])): ?>Appointment request sent successfully.
            <?php elseif ($_GET['error'] === 'invalid_schedule'): ?>Choose an approved doctor and a future appointment date and time.
            <?php elseif ($_GET['error'] === 'doctor_not_verified'): ?>That doctor is not currently available for booking.
            <?php elseif ($_GET['error'] === 'booking_failed'): ?>We could not book the appointment. Please try again.
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="modern-kpi-grid">
            <article class="modern-kpi kpi-green"><span class="kpi-icon"><i class="bi bi-heart-pulse-fill"></i></span><p>Heart Rate</p><strong><?= ($metrics['heart_rate'] ?? null) !== null ? htmlspecialchars((string) $metrics['heart_rate']) . ' <small class="metric-unit">BPM</small>' : '—' ?></strong><small><?= !empty($metrics['recorded_at']) ? 'Updated ' . htmlspecialchars(date('M j, g:i A', strtotime($metrics['recorded_at']))) : 'No reading recorded' ?></small></article>
            <article class="modern-kpi kpi-blue"><span class="kpi-icon"><i class="bi bi-lungs-fill"></i></span><p>SpO₂ Level</p><strong><?= ($metrics['spo2'] ?? null) !== null ? htmlspecialchars((string) $metrics['spo2']) . '%' : '—' ?></strong><small><?= !empty($metrics['recorded_at']) ? 'Updated ' . htmlspecialchars(date('M j, g:i A', strtotime($metrics['recorded_at']))) : 'No reading recorded' ?></small></article>
            <article class="modern-kpi kpi-orange"><span class="kpi-icon"><i class="bi bi-thermometer-half"></i></span><p>Temperature</p><strong><?= ($metrics['temperature'] ?? null) !== null ? htmlspecialchars((string) $metrics['temperature']) . '°C' : '—' ?></strong><small><?= !empty($metrics['recorded_at']) ? 'Updated ' . htmlspecialchars(date('M j, g:i A', strtotime($metrics['recorded_at']))) : 'No reading recorded' ?></small></article>
            <article class="modern-kpi kpi-purple"><span class="kpi-icon"><i class="bi bi-heart-fill"></i></span><p>Blood Pressure</p><strong><?= isset($metrics['blood_pressure_sys'], $metrics['blood_pressure_dia']) ? htmlspecialchars((string) $metrics['blood_pressure_sys']) . '/' . htmlspecialchars((string) $metrics['blood_pressure_dia']) : '—' ?></strong><small><?= !empty($metrics['recorded_at']) ? 'mmHg · updated ' . htmlspecialchars(date('M j, g:i A', strtotime($metrics['recorded_at']))) : 'No reading recorded' ?></small></article>
        </div>
        <div class="modern-analytics-grid patient-analytics-grid">
            <article id="patient-vitals" class="modern-panel">
                <div class="modern-panel-heading"><div><h2>My Health Trends</h2><p>Recorded readings from your health history</p></div><div class="range-pills" aria-label="Health chart period"><button type="button" data-patient-range="7">7D</button><button type="button" class="active" data-patient-range="30">30D</button><button type="button" data-patient-range="90">90D</button></div></div>
                <?php if ($metrics_history): ?><div class="trend-chart"><canvas id="patientVitalsChart" aria-label="Your cardiac vitals trend"></canvas></div><div class="chart-legend"><span><i class="legend-swatch heart"></i>Heart rate</span><span><i class="legend-swatch pressure"></i>Systolic BP</span><span><i class="legend-swatch oxygen"></i>SpO₂</span></div><?php else: ?><p class="modern-empty">Add a health reading to begin tracking your vital trends.</p><?php endif; ?>
            </article>
            <article id="patient-next-appointment" class="modern-panel patient-appointment-panel">
                <div class="modern-panel-heading"><div><h2>Next Appointment</h2><p>Your upcoming care schedule</p></div><i class="bi bi-calendar2-week panel-accent-icon"></i></div>
                <?php if ($upcomingAppointment): ?><div class="next-appointment-card"><span class="appointment-date"><?= htmlspecialchars(date('M j', strtotime($upcomingAppointment['appointment_date']))) ?></span><div><strong><?= htmlspecialchars($upcomingAppointment['doctor_name']) ?></strong><small><?= htmlspecialchars($upcomingAppointment['doctor_department'] ?: 'General') ?></small><small><?= htmlspecialchars(date('l, g:i A', strtotime($upcomingAppointment['appointment_date'] . ' ' . $upcomingAppointment['appointment_time']))) ?></small></div></div><a class="modern-inline-link" href="#appointments">View all appointments <i class="bi bi-arrow-right"></i></a><?php else: ?><p class="modern-empty">No upcoming appointment scheduled.</p><a class="modern-action-link" href="#book-appointment"><i class="bi bi-plus"></i> Book an appointment</a><?php endif; ?>
            </article>
        </div>
        <div class="modern-analytics-grid patient-analytics-grid patient-bottom-grid">
            <article class="modern-panel patient-readings-panel">
                <div class="modern-panel-heading"><div><h2>Recent Health Readings</h2><p>Your latest readings from your health record</p></div><a class="modern-action-link patient-add-reading" href="add-health.php"><i class="bi bi-plus-circle"></i> Add health data</a></div>
                <?php if ($metrics_history): ?>
                    <div class="patient-readings-table-wrap"><table class="patient-readings-table"><thead><tr><th>Date &amp; time</th><th>Heart</th><th>SpO₂</th><th>Temp</th><th>Blood pressure</th></tr></thead><tbody>
                    <?php foreach (array_slice($metrics_history, 0, 5) as $reading): ?>
                        <tr><td><?= htmlspecialchars(date('M j, H:i', strtotime($reading['recorded_at']))) ?></td><td><?= $reading['heart_rate'] !== null ? htmlspecialchars((string) $reading['heart_rate']) . ' bpm' : '—' ?></td><td><?= $reading['spo2'] !== null ? htmlspecialchars((string) $reading['spo2']) . '%' : '—' ?></td><td><?= $reading['temperature'] !== null ? htmlspecialchars((string) $reading['temperature']) . '°C' : '—' ?></td><td><?= isset($reading['blood_pressure_sys'], $reading['blood_pressure_dia']) ? htmlspecialchars((string) $reading['blood_pressure_sys']) . '/' . htmlspecialchars((string) $reading['blood_pressure_dia']) : '—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?><p class="modern-empty">No health readings have been recorded yet.</p><?php endif; ?>
            </article>
            <article class="modern-panel patient-doctor-panel">
                <div class="modern-panel-heading"><div><h2>My Doctor</h2><p>Your assigned care contact</p></div><i class="bi bi-person-heart panel-accent-icon"></i></div>
                <?php if ($patientDoctor): ?><div class="patient-doctor-card"><span class="patient-doctor-avatar"><i class="bi bi-person-fill"></i></span><div><strong><?= htmlspecialchars($patientDoctor['fullname']) ?></strong><small><?= htmlspecialchars($patientDoctor['department'] ?: 'General') ?></small></div></div><a class="modern-action-link" href="#book-appointment"><i class="bi bi-calendar-plus"></i> Schedule a visit</a>
                <?php else: ?><p class="modern-empty">No doctor is assigned yet.</p><a class="modern-action-link" href="#book-appointment"><i class="bi bi-calendar-plus"></i> Find a doctor</a><?php endif; ?>
            </article>
        </div>
        <article id="patient-prescriptions" class="modern-panel patient-prescription-panel">
            <div class="modern-panel-heading"><div><h2>Recent Prescriptions</h2><p><?= count($prescriptions) ?> prescriptions available in your care record</p></div><a class="modern-inline-link" href="#prescriptions">View all <i class="bi bi-arrow-right"></i></a></div>
            <?php if ($prescriptions): ?><div class="prescription-strip"><?php foreach (array_slice($prescriptions, 0, 3) as $prescription): ?><div class="prescription-item"><i class="bi bi-prescription2"></i><div><strong><?= htmlspecialchars($prescription['medication'] ?: 'Prescription') ?></strong><small><?= htmlspecialchars($prescription['dosage'] ?: 'See instructions') ?></small></div><a href="download_prescription.php?id=<?= (int) $prescription['id'] ?>" aria-label="Download prescription"><i class="bi bi-download"></i></a></div><?php endforeach; ?></div><?php else: ?><p class="modern-empty">No prescriptions have been issued yet.</p><?php endif; ?>
        </article>
        <article id="patient-predictions" class="modern-panel patient-prediction-panel">
            <div class="modern-panel-heading"><div><h2>Experimental Model Estimates</h2><p>Predictions are separate from recorded measurements and are not diagnoses.</p></div><span class="patient-model-status <?= $mlServiceAvailable ? 'is-available' : 'is-unavailable' ?>"><span></span>Model <?= $mlServiceAvailable ? 'available' : 'unavailable' ?></span></div>
            <?php if ($mlPredictionError): ?><div class="patient-ml-notice"><i class="bi bi-info-circle"></i><?= htmlspecialchars($mlPredictionError) ?></div><?php endif; ?>
            <?php if ($mlPredictions): ?>
                <div class="patient-predictions-table-wrap"><table class="patient-predictions-table"><thead><tr><th>Measure</th><th>Estimate</th><th>Created</th><th>Target date</th><th>Model</th></tr></thead><tbody>
                <?php foreach ($mlPredictions as $prediction): ?>
                    <tr><td><?= htmlspecialchars(str_replace('_', ' ', $prediction['target_name'])) ?></td>
                        <td><?= htmlspecialchars((string) $prediction['predicted_value']) ?> <?= htmlspecialchars(['heart_rate'=>'BPM','spo2'=>'%','temperature'=>'°C','blood_pressure_sys'=>'mmHg','blood_pressure_dia'=>'mmHg'][$prediction['target_name']] ?? '') ?> <small>(estimate)</small><?php if ($prediction['actual_value'] !== null): ?><br><span><?= htmlspecialchars((string) $prediction['actual_value']) ?> (recorded)</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($prediction['prediction_timestamp']) ?></td>
                        <td><?= htmlspecialchars($prediction['target_timestamp'] ?? 'Not supplied') ?></td>
                        <td><?= htmlspecialchars($prediction['model_version']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php else: ?><p class="modern-empty patient-prediction-empty">No model estimates yet. They may appear after a reading is saved while the prediction service is available.</p><?php endif; ?>
        </article>
        <?php if ($heartRateComparisons): ?>
            <article class="modern-panel patient-comparison-panel"><div class="modern-panel-heading"><div><h2>Heart Rate: Recorded vs. Estimated</h2><p>Actual measurements and model estimates</p></div></div><div class="trend-chart"><canvas id="mlComparisonChart" aria-label="Actual and predicted heart rate comparison"></canvas></div></article>
        <?php endif; ?>
    </section>

        <!-- Health Trends Modal -->
        <div class="modal fade patient-trends-view" id="healthTrendsModal" tabindex="-1" aria-labelledby="healthTrendsTitle" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="healthTrendsTitle"><i class="bi bi-graph-up"></i> Health Trends</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                        <div class="modal-body">
                    <p class="text-muted small"><i class="bi bi-shield-check"></i> Review your complete recorded health history.</p>
                <div id="noDataMessage" class="alert alert-info d-none">No health data available.</div>
                <div id="chartsContainer">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-danger text-white"><h6 class="mb-0">Heart Rate (BPM)</h6></div>
                            <div class="card-body"><div class="patient-chart-container"><canvas id="heartRateChart"></canvas></div></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white"><h6 class="mb-0">SpO₂ (%)</h6></div>
                            <div class="card-body"><div class="patient-chart-container"><canvas id="spo2Chart"></canvas></div></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark"><h6 class="mb-0">Temperature (°C)</h6></div>
                            <div class="card-body"><div class="patient-chart-container"><canvas id="tempChart"></canvas></div></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white"><h6 class="mb-0">Blood Pressure (mmHg)</h6></div>
                            <div class="card-body"><div class="patient-chart-container"><canvas id="bpChart"></canvas></div></div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr><th>Date/Time</th><th>Heart Rate</th><th>SpO₂</th><th>Temperature</th><th>Blood Pressure</th></tr>
                        </thead>
                        <tbody id="healthMetricsTableBody">
                            <!-- Data inserted when the modal opens -->
                        </tbody>
                    </table>
                </div>
                </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    <section id="book-appointment" class="modern-panel patient-legacy-panel patient-booking-panel" aria-labelledby="book-appointment-title">
      <div class="modern-panel-heading"><div><h2 id="book-appointment-title">Book an Appointment</h2><p>Choose a department, verified doctor, date, and time</p></div><i class="bi bi-calendar2-plus panel-accent-icon"></i></div>
      <form action="book_appointment.php" method="POST" class="row g-3 patient-appointment-form">
        <input type="hidden" name="patient_csrf" value="<?= htmlspecialchars($_SESSION['patient_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-md-3">
            <label for="department_select" class="form-label">1. Select Department</label>
            <select class="form-select" id="department_select" required>
                <option value="">Choose a department</option>
                <?php foreach (array_keys($doctors_by_department) as $department): ?>
                    <option value="<?= htmlspecialchars($department, ENT_QUOTES) ?>"><?= htmlspecialchars($department) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="doctor_id" class="form-label">2. Select Doctor <span class="verified-badge" title="Verified doctor" aria-label="Verified doctor"><i class="bi bi-patch-check-fill"></i></span></label>
            <select class="form-select" id="doctor_id" name="doctor_id" required disabled>
                <option value="">Choose a department first</option>
                <?php foreach ($doctors_by_department as $department => $department_doctors): ?>
                    <?php foreach ($department_doctors as $doc): ?>
                        <option value="<?= (int) $doc['id'] ?>" data-department="<?= htmlspecialchars($department, ENT_QUOTES) ?>" hidden><?= htmlspecialchars($doc['fullname']) ?> &#10003;</option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label for="appointment_date" class="form-label">Date</label>
            <input type="date" class="form-control" id="appointment_date" name="appointment_date" required>
        </div>

        <div class="col-md-3">
            <label for="appointment_time" class="form-label">Time</label>
            <input type="time" class="form-control" id="appointment_time" name="appointment_time" required>
        </div>

        <div class="col-12">
            <label for="notes" class="form-label">Notes (Optional)</label>
            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
        </div>

        <div class="col-12">
            <button type="submit" class="modern-submit-button">
                <i class="bi bi-calendar-plus"></i> Book Appointment
            </button>
        </div>
      </form>
    </section>

    <section id="appointments" class="modern-panel patient-legacy-panel" aria-labelledby="patient-appointments-title">
      <div class="modern-panel-heading"><div><h2 id="patient-appointments-title">Your Appointments</h2><p><?= count($appointments) ?> appointments in your care schedule</p></div><i class="bi bi-calendar-check panel-accent-icon"></i></div>
      <div class="patient-legacy-table-wrap">
        <table class="patient-legacy-table">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Department</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($appointments): foreach ($appointments as $appt): ?>
                    <tr>
                        <td><?= htmlspecialchars($appt['doctor_name']) ?><?php if (($appt['doctor_verification_status'] ?? '') === 'approved'): ?> <span class="verified-badge" title="Verified doctor"><i class="bi bi-patch-check-fill"></i></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($appt['doctor_department'] ?? 'General') ?></td>
                        <td><?= $appt['appointment_date'] ?></td>
                        <td><?= $appt['appointment_time'] ?></td>
                        <td>
                            <span class="badge bg-<?= 
                                $appt['status'] === 'completed' ? 'success' : 
                                ($appt['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                                <?= ucfirst($appt['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($appt['notes']) ?></td>
                        <td>
                            <?php if ($appt['status'] === 'pending'): ?>
                                <form method="POST" onsubmit="return confirm('Remove this appointment?');">
                                    <input type="hidden" name="patient_csrf" value="<?= htmlspecialchars($_SESSION['patient_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="cancel_appointment" value="1">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appt['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove appointment" aria-label="Remove appointment">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center">No appointments found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
      </div>
    </section>

    <section id="prescriptions" class="modern-panel patient-legacy-panel" aria-labelledby="patient-prescriptions-title">
      <div class="modern-panel-heading"><div><h2 id="patient-prescriptions-title">Prescriptions</h2><p><?= count($prescriptions) ?> prescriptions from your care team</p></div><i class="bi bi-prescription2 panel-accent-icon"></i></div>
      <div class="patient-legacy-table-wrap">
        <table class="patient-legacy-table">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Medication</th>
                    <th>Dosage</th>
                    <th>Instructions</th>
                    <th>Prescription Notes</th>
                    <th>Date Issued</th>
                    <th>Download</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($prescriptions): foreach ($prescriptions as $presc): ?>
                    <tr>
                        <td><?= htmlspecialchars($presc['doctor_name']) ?><?php if (($presc['doctor_verification_status'] ?? '') === 'approved'): ?> <span class="verified-badge" title="Verified doctor"><i class="bi bi-patch-check-fill"></i></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($presc['medication'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($presc['dosage'] ?? 'N/A') ?></td>
                        <td><?= nl2br(htmlspecialchars($presc['instructions'] ?? 'N/A')) ?></td>
                        <td><?= nl2br(htmlspecialchars($presc['prescription'])) ?></td>
                        <td><?= date("d M Y, h:i A", strtotime($presc['created_at'])) ?></td>
                        <td>
                            <a href="download_prescription.php?id=<?= (int) $presc['id'] ?>" class="btn btn-sm btn-outline-primary" download>
                                <i class="bi bi-download"></i> Download
                            </a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center">No prescriptions found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
      </div>
    </section>

    <section id="doctor-suggestions" class="modern-panel patient-legacy-panel" aria-labelledby="doctor-suggestions-title">
      <div class="modern-panel-heading"><div><h2 id="doctor-suggestions-title">Doctor Suggestions</h2><p><?= count($doctorSuggestions) ?> care suggestions from your appointments</p></div><i class="bi bi-chat-square-heart panel-accent-icon"></i></div>
      <?php if ($doctorSuggestions): ?>
        <div class="patient-suggestions-list">
          <?php foreach ($doctorSuggestions as $doctorSuggestion): ?>
            <article class="patient-suggestion-card">
              <div class="patient-suggestion-meta">
                <strong><?= htmlspecialchars($doctorSuggestion['doctor_name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($doctorSuggestion['department']) ? ' · ' . htmlspecialchars($doctorSuggestion['department'], ENT_QUOTES, 'UTF-8') : '' ?></strong>
                <time datetime="<?= htmlspecialchars(date('c', strtotime($doctorSuggestion['created_at'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($doctorSuggestion['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
              </div>
              <p><?= nl2br(htmlspecialchars($doctorSuggestion['suggestion'], ENT_QUOTES, 'UTF-8')) ?></p>
              <small>Related appointment: <?= htmlspecialchars(date('M j, Y', strtotime($doctorSuggestion['appointment_date'])), ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(date('g:i A', strtotime($doctorSuggestion['appointment_time'])), ENT_QUOTES, 'UTF-8') ?></small>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="modern-empty">Your doctor has not shared any suggestions yet.</p>
      <?php endif; ?>
    </section>
</div>

<aside class="health-assistant" aria-label="MedConnect Health Assistant">
    <div class="health-assistant-header">
        <div>
            <span class="health-assistant-kicker">MedConnect AI</span>
            <h2>Health Assistant</h2>
        </div>
        <a href="#book-appointment" class="btn btn-sm btn-outline-primary" title="Book an appointment">
            <i class="bi bi-calendar2-plus" aria-hidden="true"></i>
            <span class="visually-hidden">Book an appointment</span>
        </a>
        <button type="button" class="health-assistant-close" id="healthAssistantClose" aria-label="Close health assistant">×</button>
        <span class="health-assistant-status" aria-label="Ready"></span>
    </div>
    <div class="health-assistant-messages" id="healthAssistantMessages" aria-live="polite">
        <div class="health-assistant-message model">Hi <?= htmlspecialchars(explode(' ', $patient['fullname'])[0]) ?>. Ask me about your readings or general health information. I cannot diagnose or replace a clinician.</div>
    </div>
    <form id="healthAssistantForm" class="health-assistant-form" data-role="patient" data-session-id="<?= htmlspecialchars(medconnect_assistant_session_id('patient'), ENT_QUOTES, 'UTF-8') ?>" data-chat-endpoint="gemini-chat.php" data-csrf="<?= htmlspecialchars($_SESSION['assistant_csrf'] ??= bin2hex(random_bytes(32)), ENT_QUOTES, 'UTF-8') ?>">
        <label class="visually-hidden" for="healthAssistantInput">Ask the health assistant</label>
        <input id="healthAssistantInput" name="question" maxlength="2000" autocomplete="off" placeholder="Ask a health question..." required>
        <button type="submit" aria-label="Send question"><i class="bi bi-arrow-up"></i></button>
    </form>
    <p class="health-assistant-note">For emergencies, contact local emergency services.</p>
</aside>
<button type="button" class="health-assistant-launcher" id="healthAssistantLauncher" aria-label="Open health assistant">✚ <span>Health assistant</span></button>

<!-- View Profile Modal (NEW) -->
<div class="modal fade" id="viewProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Your Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-primary d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                        <i class="bi bi-person-fill text-white" style="font-size: 2.5rem;"></i>
                    </div>
                    <h4 class="mt-3"><?= htmlspecialchars($patient['fullname']) ?></h4>
                    <p class="text-muted">Patient ID: <?= htmlspecialchars($patient['id']) ?></p>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6>Email</h6>
                        <p><?= htmlspecialchars($patient['email']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6>Phone</h6>
                        <p><?= htmlspecialchars($patient['phone']) ?></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6>Age</h6>
                        <p><?= htmlspecialchars($patient['age']) ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateProfileModal" data-bs-dismiss="modal">
                    <i class="bi bi-pencil-square"></i> Edit Profile
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Profile Modal (Existing) -->
<div class="modal fade" id="updateProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="patient_csrf" value="<?= htmlspecialchars($_SESSION['patient_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-header">
                <h5 class="modal-title">Update Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="update_profile" value="1" />
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" class="form-control" maxlength="100" value="<?= htmlspecialchars($patient['fullname']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" maxlength="100" value="<?= htmlspecialchars($patient['email']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" maxlength="20" value="<?= htmlspecialchars($patient['phone']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Age</label>
                    <input type="number" name="age" class="form-control" value="<?= htmlspecialchars($patient['age']) ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const departmentSelect = document.getElementById('department_select');
const doctorSelect = document.getElementById('doctor_id');
const doctorOptions = Array.from(doctorSelect.querySelectorAll('option[data-department]'));

departmentSelect.addEventListener('change', function () {
    const department = this.value;
    doctorSelect.value = '';
    doctorSelect.disabled = !department;
    doctorSelect.options[0].textContent = department ? 'Choose a doctor' : 'Choose a department first';
    doctorOptions.forEach(function (option) {
        option.hidden = option.dataset.department !== department;
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const healthHistory = <?= json_encode($metrics_history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const trendChartInstances = {};

    function createChart(canvasId, label, labels, data, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;
        if (trendChartInstances[canvasId]) trendChartInstances[canvasId].destroy();

        trendChartInstances[canvasId] = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: data,
                    borderColor: color,
                    backgroundColor: color + '33',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 8,
                            maxRotation: 45,
                            minRotation: 0
                        }
                    },
                    y: { beginAtZero: false }
                }
            }
        });
    }

    function createBPChart(labels, systolicData, diastolicData) {
        const canvas = document.getElementById('bpChart');
        if (!canvas || typeof Chart === 'undefined') return;
        if (trendChartInstances.bloodPressure) trendChartInstances.bloodPressure.destroy();

        trendChartInstances.bloodPressure = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Systolic', data: systolicData, borderColor: '#6c757d', backgroundColor: '#6c757d33', tension: 0.3 },
                    { label: 'Diastolic', data: diastolicData, borderColor: '#495057', backgroundColor: '#49505733', tension: 0.3 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 8,
                            maxRotation: 45,
                            minRotation: 0
                        }
                    },
                    y: { beginAtZero: false }
                }
            }
        });
    }

    document.getElementById('healthTrendsModal')?.addEventListener('shown.bs.modal', function () {
        const noDataMessage = document.getElementById('noDataMessage');
        const chartsContainer = document.getElementById('chartsContainer');
        const tableBody = document.getElementById('healthMetricsTableBody');

        if (!healthHistory.length) {
            noDataMessage.classList.remove('d-none');
            chartsContainer.classList.add('d-none');
            return;
        }

        noDataMessage.classList.add('d-none');
        chartsContainer.classList.remove('d-none');

        const labels = healthHistory.map(entry => new Date(entry.recorded_at).toLocaleString());
        const heartRates = healthHistory.map(entry => entry.heart_rate || null);
        const spo2Values = healthHistory.map(entry => entry.spo2 || null);
        const temperatures = healthHistory.map(entry => entry.temperature || null);
        const systolicValues = healthHistory.map(entry => entry.blood_pressure_sys || null);
        const diastolicValues = healthHistory.map(entry => entry.blood_pressure_dia || null);

        tableBody.innerHTML = '';
        healthHistory.forEach(function (entry) {
            const row = document.createElement('tr');
            const values = [
                new Date(entry.recorded_at).toLocaleString(),
                entry.heart_rate || 'N/A',
                entry.spo2 || 'N/A',
                entry.temperature || 'N/A',
                entry.blood_pressure_sys && entry.blood_pressure_dia
                    ? `${entry.blood_pressure_sys}/${entry.blood_pressure_dia}`
                    : 'N/A'
            ];
            values.forEach(function (value) {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            tableBody.appendChild(row);
        });

        if (typeof Chart === 'undefined') {
            noDataMessage.textContent = 'Trend charts are temporarily unavailable. Please check your connection and try again.';
            noDataMessage.classList.remove('d-none');
            return;
        }

        createChart('heartRateChart', 'Heart Rate', labels, heartRates, '#dc3545');
        createChart('spo2Chart', 'SpO₂', labels, spo2Values, '#17a2b8');
        createChart('tempChart', 'Temperature', labels, temperatures, '#ffc107');
        createBPChart(labels, systolicValues, diastolicValues);
    });

});
</script>
<?php if ($heartRateComparisons): ?>
<script>
const comparisonRows = <?= json_encode($heartRateComparisons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
new Chart(document.getElementById('mlComparisonChart'), {
  type: 'line',
  data: { labels: comparisonRows.map(row => row.target_timestamp), datasets: [
    { label: 'Predicted heart rate', data: comparisonRows.map(row => Number(row.predicted_value)), borderColor: '#7b61a8', tension: .2 },
    { label: 'Actual recorded heart rate', data: comparisonRows.map(row => Number(row.actual_value)), borderColor: '#dc3545', tension: .2 }
  ] }, options: { responsive: true, interaction: { mode: 'index', intersect: false } }
});
</script>
<?php endif; ?>
    <script src="js/design-switch.js?v=2"></script>
    <script src="js/health-assistant.js?v=2"></script>
    <script>
      const patientVitalsCanvas = document.getElementById('patientVitalsChart');
      if (patientVitalsCanvas) {
            const patientHistory = <?= json_encode($metrics_history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.slice().reverse();
            const patientVitalsChart = new Chart(patientVitalsCanvas, {
              type: 'line',
              data: { labels: [], datasets: [
                { label: 'Heart rate', data: [], borderColor: '#43d6a0', backgroundColor: 'rgba(67,214,160,.12)', tension: .35, spanGaps: true, pointRadius: 2, fill: true },
                { label: 'Systolic BP', data: [], borderColor: '#b49aff', tension: .35, spanGaps: true, pointRadius: 2 },
                { label: 'SpO₂', data: [], borderColor: '#ff8a35', tension: .35, spanGaps: true, pointRadius: 2 }
              ] },
              options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }, scales: { x: { grid: { display: false }, ticks: { color: '#777b83', maxTicksLimit: 7, font: { size: 10 } } }, y: { grid: { color: 'rgba(119,123,131,.12)' }, ticks: { color: '#777b83', font: { size: 10 } } } } }
            });
            window.addEventListener('medconnect:themechange', function (event) {
              const color = event.detail.dark ? '#777b83' : '#52646b';
              patientVitalsChart.options.scales.x.ticks.color = color;
              patientVitalsChart.options.scales.y.ticks.color = color;
              patientVitalsChart.options.scales.y.grid.color = event.detail.dark ? 'rgba(119,123,131,.12)' : 'rgba(82,100,107,.15)';
              patientVitalsChart.update();
            });
            function updatePatientVitals(days) {
              const cutoff = Date.now() - days * 24 * 60 * 60 * 1000;
              const visible = patientHistory.filter((reading) => new Date(reading.recorded_at.replace(' ', 'T')).getTime() >= cutoff);
              patientVitalsChart.data.labels = visible.map((reading) => new Date(reading.recorded_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }));
              patientVitalsChart.data.datasets[0].data = visible.map((reading) => reading.heart_rate || null);
              patientVitalsChart.data.datasets[1].data = visible.map((reading) => reading.blood_pressure_sys || null);
              patientVitalsChart.data.datasets[2].data = visible.map((reading) => reading.spo2 || null);
              patientVitalsChart.update();
            }
            document.querySelectorAll('[data-patient-range]').forEach((button) => {
              button.addEventListener('click', function () {
                document.querySelectorAll('[data-patient-range]').forEach((item) => item.classList.remove('active'));
                button.classList.add('active');
                updatePatientVitals(Number(button.dataset.patientRange));
              });
            });
            updatePatientVitals(30);
          }
        </script>
</body>
</html>

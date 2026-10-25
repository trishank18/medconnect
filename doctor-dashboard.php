<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['doctor_id'])) {
    header("Location: doctor-login.html");
    exit();
}
$_SESSION['doctor_csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['doctor_csrf'], (string) ($_POST['doctor_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request token. Reload the dashboard and try again.');
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    // Fetch doctor info
    $stmt = $conn->prepare("SELECT id, fullname, email, phone, department, username, verification_status, created_at FROM doctors WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['doctor_id']);
    $stmt->execute();
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        unset($_SESSION['doctor_id'], $_SESSION['doctor_name'], $_SESSION['doctor_csrf']);
        header("Location: doctor-login.html?error=not_found");
        exit();
    }

      $applicationStatus = $doctor['verification_status'] ?? 'pending';
      $applicationStatusLabel = $applicationStatus === 'approved' ? 'Accepted' : ucfirst($applicationStatus);
      $canAccessAppointments = $applicationStatus === 'approved';

    $directoryStmt = $conn->query("SELECT id, fullname, email, phone, department FROM doctors WHERE verification_status = 'approved' ORDER BY department, fullname");
    $doctorsByDepartment = [];
    foreach ($directoryStmt->fetchAll(PDO::FETCH_ASSOC) as $directoryDoctor) {
      $doctorsByDepartment[$directoryDoctor['department']][] = $directoryDoctor;
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $applicationStatus === 'approved') {
        if (isset($_POST['update_profile'])) {
            $fullname = trim((string) ($_POST['fullname'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $department = trim((string) ($_POST['department'] ?? ''));
            if ($fullname === '' || strlen($fullname) > 100 || preg_match('/[\r\n]/', $fullname)
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                || !preg_match('/^[+0-9(). -]{7,20}$/', $phone)
                || $department === '' || strlen($department) > 50) {
                header('Location: doctor-dashboard.php?error=invalid_profile#updateProfileModal');
                exit();
            }
            $duplicateEmail = $conn->prepare('SELECT 1 FROM doctors WHERE email = :email AND id <> :id LIMIT 1');
            $duplicateEmail->execute([':email' => $email, ':id' => (int) $_SESSION['doctor_id']]);
            if ($duplicateEmail->fetchColumn()) {
                header('Location: doctor-dashboard.php?error=duplicate_email#updateProfileModal');
                exit();
            }
            
            $updateStmt = $conn->prepare("UPDATE doctors SET fullname = :fullname, email = :email, phone = :phone, department = :department WHERE id = :id");
            $updateStmt->bindParam(':fullname', $fullname);
            $updateStmt->bindParam(':email', $email);
            $updateStmt->bindParam(':phone', $phone);
            $updateStmt->bindParam(':department', $department);
            $updateStmt->bindParam(':id', $_SESSION['doctor_id']);
            $updateStmt->execute();
            
            header('Location: doctor-dashboard.php?profile_updated=1');
            exit();
        } elseif (isset($_POST['add_prescription'])) {
            $patient_id = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
            $appointment_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            $prescription = trim((string) ($_POST['prescription'] ?? ''));
            $medication = trim((string) ($_POST['medication'] ?? ''));
            $dosage = trim((string) ($_POST['dosage'] ?? ''));
            $instructions = trim((string) ($_POST['instructions'] ?? ''));

            if (!$patient_id || !$appointment_id || $prescription === ''
                || strlen($prescription) > 10000 || strlen($medication) > 100
                || strlen($dosage) > 50 || strlen($instructions) > 5000) {
              header('Location: doctor-dashboard.php?error=invalid_prescription#appointments-tab-pane');
              exit();
            }
            
            $authorizationStmt = $conn->prepare("SELECT 1 FROM appointments WHERE id = :appointment_id AND patient_id = :patient_id AND doctor_id = :doctor_id LIMIT 1");
            $authorizationStmt->execute([
              ':appointment_id' => $appointment_id,
              ':patient_id' => $patient_id,
              ':doctor_id' => $_SESSION['doctor_id'],
            ]);
            if (!$authorizationStmt->fetchColumn()) {
              header('Location: doctor-dashboard.php?error=unauthorized_patient');
              exit();
            }
            $insertStmt = $conn->prepare("INSERT INTO patient_prescriptions 
                (doctor_id, patient_id, appointment_id, prescription, medication, dosage, instructions) 
                VALUES (:doctor_id, :patient_id, :appointment_id, :prescription, :medication, :dosage, :instructions)");
            $insertStmt->bindParam(':doctor_id', $_SESSION['doctor_id']);
            $insertStmt->bindParam(':patient_id', $patient_id);
            $insertStmt->bindParam(':appointment_id', $appointment_id);
            $insertStmt->bindParam(':prescription', $prescription);
            $insertStmt->bindParam(':medication', $medication);
            $insertStmt->bindParam(':dosage', $dosage);
            $insertStmt->bindParam(':instructions', $instructions);
            $insertStmt->execute();
          } elseif (isset($_POST['delete_prescription'])) {
            $prescriptionId = filter_input(INPUT_POST, 'prescription_id', FILTER_VALIDATE_INT);

            if ($prescriptionId) {
              $deleteStmt = $conn->prepare("DELETE FROM patient_prescriptions WHERE id = :id AND doctor_id = :doctor_id");
              $deleteStmt->execute([
                ':id' => $prescriptionId,
                ':doctor_id' => $_SESSION['doctor_id']
              ]);
            }
        } elseif (isset($_POST['update_status'])) {
            $appointment_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? '';
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($appointment_id && in_array($status, ['pending', 'completed', 'cancelled'], true) && strlen($notes) <= 3000) {
              $updateStmt = $conn->prepare("UPDATE appointments SET status = :status, notes = :notes WHERE id = :id AND doctor_id = :doctor_id");
              $updateStmt->execute([':status' => $status, ':notes' => $notes, ':id' => $appointment_id, ':doctor_id' => $_SESSION['doctor_id']]);
            }
        }
    }

    // Count of patients
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT p.id) AS total
      FROM patients p
      WHERE :access_granted = 1
        AND (p.doctor_id = :assigned_doctor_id OR EXISTS (
          SELECT 1 FROM appointments a
          WHERE a.patient_id = p.id AND a.doctor_id = :appointment_doctor_id
        ))");
    $stmt->execute([
      ':access_granted' => $canAccessAppointments ? 1 : 0,
      ':assigned_doctor_id' => (int) $_SESSION['doctor_id'],
      ':appointment_doctor_id' => (int) $_SESSION['doctor_id'],
    ]);
    $patientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch patients with latest health metrics
    $stmt = $conn->prepare("
        SELECT p.id, p.fullname, p.age, p.email, p.phone,
               hm.heart_rate, hm.spo2, hm.temperature,
               hm.blood_pressure_sys, hm.blood_pressure_dia, hm.recorded_at
        FROM patients p
        LEFT JOIN health_metrics hm ON hm.id = (
            SELECT latest.id
            FROM health_metrics latest
            WHERE latest.patient_id = p.id
            ORDER BY latest.recorded_at DESC, latest.id DESC
            LIMIT 1
        )
        WHERE :access_granted = 1
          AND (p.doctor_id = :assigned_doctor_id OR EXISTS (
              SELECT 1 FROM appointments a
              WHERE a.patient_id = p.id AND a.doctor_id = :appointment_doctor_id
          ))
        ORDER BY p.fullname
    ");
    $stmt->execute([
      ':assigned_doctor_id' => (int) $_SESSION['doctor_id'],
      ':appointment_doctor_id' => (int) $_SESSION['doctor_id'],
      ':access_granted' => $canAccessAppointments ? 1 : 0,
    ]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch health history for each patient
    foreach ($patients as &$patient) {
        $stmt = $conn->prepare("
            SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
            FROM health_metrics
            WHERE patient_id = :patient_id
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY recorded_at DESC, id DESC
        ");
        $stmt->bindParam(':patient_id', $patient['id']);
        $stmt->execute();
        $patient['health_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        try {
            $predictionStmt = $conn->prepare("SELECT target_name, predicted_value, actual_value, model_version, prediction_timestamp, target_timestamp
                FROM ml_predictions WHERE patient_id = :patient_id ORDER BY prediction_timestamp DESC LIMIT 5");
            $predictionStmt->execute([':patient_id' => $patient['id']]);
            $patient['ml_predictions'] = $predictionStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ignored) {
            $patient['ml_predictions'] = [];
        }
    }
    unset($patient); // Break the reference

    $patientsById = [];
    foreach ($patients as $authorizedPatient) {
        $patientsById[(int) $authorizedPatient['id']] = $authorizedPatient;
    }

    $stmt = $conn->prepare("
      SELECT a.*, p.fullname AS patient_name,
             hm.heart_rate AS latest_heart_rate,
             hm.spo2 AS latest_spo2,
             hm.temperature AS latest_temperature,
             hm.blood_pressure_sys AS latest_blood_pressure_sys,
             hm.blood_pressure_dia AS latest_blood_pressure_dia,
             hm.recorded_at AS vitals_recorded_at
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.id
        LEFT JOIN health_metrics hm ON hm.id = (
          SELECT latest.id
          FROM health_metrics latest
          WHERE latest.patient_id = p.id
          ORDER BY latest.recorded_at DESC, latest.id DESC
          LIMIT 1
        )
        WHERE a.doctor_id = :doc_id
          AND :access_granted = 1
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bindParam(':doc_id', $_SESSION['doctor_id']);
    $stmt->bindValue(':access_granted', $canAccessAppointments ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $readingsStmt = $conn->prepare("SELECT COUNT(*) FROM health_metrics hm
      WHERE hm.recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND :access_granted = 1
        AND EXISTS (
          SELECT 1 FROM patients p
          WHERE p.id = hm.patient_id
            AND (p.doctor_id = :assigned_doctor_id OR EXISTS (
              SELECT 1 FROM appointments a
              WHERE a.patient_id = p.id AND a.doctor_id = :appointment_doctor_id
            ))
        )");
    $readingsStmt->execute([
      ':assigned_doctor_id' => (int) $_SESSION['doctor_id'],
      ':appointment_doctor_id' => (int) $_SESSION['doctor_id'],
      ':access_granted' => $canAccessAppointments ? 1 : 0,
    ]);
    $readingsReceived = (int) $readingsStmt->fetchColumn();

    $prescriptionStmt = $conn->prepare("SELECT id, appointment_id, prescription, medication, dosage, instructions, created_at
      FROM patient_prescriptions
      WHERE doctor_id = :doc_id
      ORDER BY created_at DESC");
    $prescriptionStmt->bindParam(':doc_id', $_SESSION['doctor_id']);
    $prescriptionStmt->execute();
    $prescriptionsByAppointment = [];
    foreach ($prescriptionStmt->fetchAll(PDO::FETCH_ASSOC) as $prescription) {
      $prescriptionsByAppointment[$prescription['appointment_id']][] = $prescription;
    }

} catch (PDOException $e) {
    error_log('MedConnect doctor dashboard query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The doctor workspace is temporarily unavailable. Please try again later.');
}
$doctorDisplayName = preg_replace('/^(?:Dr\.?\s*)+/i', '', trim((string) $doctor['fullname']));
$mlHealthContext = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
$mlHealth = @file_get_contents(rtrim(getenv('ML_API_URL') ?: 'http://127.0.0.1:8001', '/') . '/health', false, $mlHealthContext);
$mlHealthData = is_string($mlHealth) ? json_decode($mlHealth, true) : null;
$mlServiceAvailable = is_array($mlHealthData) && ($mlHealthData['status'] ?? '') === 'ok';

$pendingAppointments = count(array_filter($appointments, static fn($appointment) => $appointment['status'] === 'pending'));
$todayAppointments = count(array_filter($appointments, static fn($appointment) => $appointment['appointment_date'] === date('Y-m-d')));
$criticalPatients = array_values(array_filter($patients, static function ($patient): bool {
  $heartRate = $patient['heart_rate'] !== null ? (float) $patient['heart_rate'] : null;
  $spo2 = $patient['spo2'] !== null ? (float) $patient['spo2'] : null;
  return ($heartRate !== null && ($heartRate < 50 || $heartRate > 120)) || ($spo2 !== null && $spo2 < 90);
}));
usort($patients, static function (array $left, array $right) use ($criticalPatients): int {
  $leftPriority = in_array($left, $criticalPatients, true) ? 0 : 1;
  $rightPriority = in_array($right, $criticalPatients, true) ? 0 : 1;
  return ($leftPriority <=> $rightPriority) ?: strcasecmp((string) $left['fullname'], (string) $right['fullname']);
});
$statusMix = [];
foreach ($appointments as $appointment) {
  $status = ucfirst((string) ($appointment['status'] ?: 'scheduled'));
  $statusMix[$status] = ($statusMix[$status] ?? 0) + 1;
}
$mixColors = ['Completed' => '#43d6a0', 'Pending' => '#f1c84a', 'Cancelled' => '#ff646c'];
$mixStops = [];
$mixOffset = 0.0;
$mixTotal = max(1, count($appointments));
foreach ($statusMix as $status => $count) {
  $start = $mixOffset;
  $mixOffset += ((int) $count / $mixTotal) * 100;
  $mixStops[] = ($mixColors[$status] ?? '#777b83') . ' ' . round($start, 2) . '% ' . round($mixOffset, 2) . '%';
}
$appointmentMixGradient = $mixStops ? implode(', ', $mixStops) : '#36383b 0 100%';
$weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
$weeklyAdmissions = array_fill_keys($weekdays, 0);
$weeklyScheduled = array_fill_keys($weekdays, 0);
$weekStart = strtotime('monday this week');
$weekEnd = strtotime('monday next week');
foreach ($appointments as $appointment) {
  $appointmentTimestamp = strtotime($appointment['appointment_date']);
  if ($appointmentTimestamp < $weekStart || $appointmentTimestamp >= $weekEnd) continue;
  $day = date('D', $appointmentTimestamp);
  if (isset($weeklyScheduled[$day])) {
    $weeklyScheduled[$day]++;
    if (($appointment['status'] ?? '') === 'completed') {
      $weeklyAdmissions[$day]++;
    }
  }
}
$trendRows = [];
foreach ($patients as $patient) {
  foreach (array_reverse($patient['health_history'] ?? []) as $reading) {
    $dateKey = date('Y-m-d', strtotime($reading['recorded_at']));
    if (!isset($trendRows[$dateKey])) {
      $trendRows[$dateKey] = ['heart_rate' => [], 'blood_pressure_sys' => [], 'spo2' => []];
    }
    foreach (['heart_rate', 'blood_pressure_sys', 'spo2'] as $metric) {
      if ($reading[$metric] !== null && $reading[$metric] !== '') {
        $trendRows[$dateKey][$metric][] = (float) $reading[$metric];
      }
    }
  }
}
ksort($trendRows);
$trendRows = array_slice($trendRows, -90, 90, true);
$trendDates = array_keys($trendRows);
$trendLabels = array_map(static fn($date) => date('M j', strtotime($date)), $trendDates);
$trendData = [];
foreach (['heart_rate', 'blood_pressure_sys', 'spo2'] as $metric) {
  $trendData[$metric] = array_map(static function ($row) use ($metric) {
    return $row[$metric] ? round(array_sum($row[$metric]) / count($row[$metric]), 1) : null;
  }, $trendRows);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Doctor Dashboard - MedConnect</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .prescription-list { max-height: 150px; overflow-y: auto; }
    .badge-completed { background-color: #28a745; }
    .badge-pending { background-color: #ffc107; color: #212529; }
    .badge-cancelled { background-color: #dc3545; }
    .prescription-card { border-left: 4px solid #0d6efd; margin-bottom: 10px; }
    .department-card { height: 100%; }
    .status-dropdown { min-width: 120px; }
    .profile-icon {
      width: 36px; height: 36px; border-radius: 50%;
      background-color: #0d6efd; color: white;
      display: flex; align-items: center; justify-content: center;
      font-weight: bold;
    }
    .health-metric-card { transition: transform 0.2s; }
    .health-metric-card:hover { transform: translateY(-3px); }
    .trend-btn { padding: 2px 8px; font-size: 0.8rem; }
    .chart-container { height: 300px; }
  </style>
  <link rel="stylesheet" href="css/business.css">
  <link rel="stylesheet" href="css/dashboard-modern.css?v=20261025-11">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="js/theme-init.js"></script>
  <script src="js/dashboard-shell.js" defer></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
</head>
<body class="bg-light modern-dashboard doctor-modern-dashboard" data-user-name="<?= htmlspecialchars($doctorDisplayName, ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($doctor['department'] ?: 'Doctor', ENT_QUOTES, 'UTF-8') ?>">
  <div class="container py-5">
    <!-- Updated Header with Profile Dropdown -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2>Welcome, Dr. <?= htmlspecialchars($doctorDisplayName) ?> <small class="text-muted">(<?= htmlspecialchars($doctor['department']) ?>)</small></h2>
      <div>
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
          <i class="bi bi-pencil-square"></i> Edit Profile
        </button>
        
        <div class="dropdown d-inline-block">
          <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="profile-icon me-2">
              <?= strtoupper(substr($doctorDisplayName, 0, 1)) ?>
            </div>
            <?= htmlspecialchars(explode(' ', $doctorDisplayName)[0]) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
            <li><h6 class="dropdown-header">Dr. <?= htmlspecialchars($doctorDisplayName) ?></h6></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#viewProfileModal">
              <i class="bi bi-person-lines-fill"></i> View Profile
            </a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php?role=doctor">
              <i class="bi bi-box-arrow-right"></i> Logout
            </a></li>
          </ul>
        </div>
      </div>
    </div>

    <section class="modern-overview" aria-labelledby="overview-title">
      <div class="modern-overview-header">
        <div>
          <p class="modern-kicker">Clinical workspace</p>
          <?php $doctorFirstName = explode(' ', $doctorDisplayName)[0]; ?>
          <h1 id="overview-title">Good <?= date('G') < 12 ? 'morning' : (date('G') < 18 ? 'afternoon' : 'evening') ?>, Dr. <?= htmlspecialchars($doctorFirstName) ?> <span aria-hidden="true">✦</span></h1>
          <p><?= date('l, F j, Y') ?> · <?= htmlspecialchars($doctor['department']) ?></p>
        </div>
        <span class="monitoring-pill <?= $mlServiceAvailable ? 'is-available' : 'is-unavailable' ?>"><span></span>Prediction service <?= $mlServiceAvailable ? 'online' : 'unavailable' ?></span>
      </div>
      <?php if (isset($_GET['profile_updated']) || isset($_GET['error'])): ?>
        <div class="alert <?= isset($_GET['error']) ? 'alert-warning' : 'alert-success' ?>" role="status">
          <?php if (isset($_GET['profile_updated'])): ?>Profile updated successfully.
          <?php elseif ($_GET['error'] === 'invalid_profile'): ?>Check the profile fields and try again.
          <?php elseif ($_GET['error'] === 'duplicate_email'): ?>That email is already used by another account.
          <?php elseif ($_GET['error'] === 'unauthorized_patient'): ?>That patient or appointment is not assigned to your account.
          <?php elseif ($_GET['error'] === 'invalid_prescription'): ?>Complete the prescription fields within the allowed length.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!$canAccessAppointments): ?>
        <div class="doctor-application-note <?= $applicationStatus === 'rejected' ? 'is-rejected' : 'is-pending' ?>"><i class="bi bi-shield-lock"></i><div><strong>Doctor application <?= htmlspecialchars(strtolower($applicationStatusLabel)) ?></strong><span>Patient records and appointment tools are available after approval.</span></div><span class="doctor-application-badge"><?= htmlspecialchars($applicationStatusLabel) ?></span></div>
      <?php endif; ?>

      <div class="modern-kpi-grid">
        <article class="modern-kpi kpi-green"><span class="kpi-icon"><i class="bi bi-people-fill"></i></span><p>Active Patients</p><strong><?= (int) $patientCount ?></strong><small>Authorized care network</small></article>
        <article class="modern-kpi kpi-orange"><span class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></span><p>Patients Requiring Review</p><strong><?= count($criticalPatients) ?></strong><small>HR &lt;50 or &gt;120 BPM, or SpO₂ &lt;90%</small></article>
        <article class="modern-kpi kpi-yellow"><span class="kpi-icon"><i class="bi bi-calendar-check"></i></span><p>Today's Appointments</p><strong><?= $todayAppointments ?></strong><small><?= $pendingAppointments ?> pending overall</small></article>
        <article class="modern-kpi kpi-purple"><span class="kpi-icon"><i class="bi bi-activity"></i></span><p>Health Readings</p><strong><?= $readingsReceived ?></strong><small>Received in the last 30 days</small></article>
      </div>

      <div class="modern-analytics-grid">
        <article class="modern-panel">
          <div class="modern-panel-heading"><div><h2>Weekly Admissions</h2><p>Completed versus scheduled appointments</p></div></div>
          <div class="bar-chart" aria-label="Weekly admissions chart">
            <?php foreach ($weekdays as $day): ?>
              <?php $maxValue = max(1, max($weeklyAdmissions), max($weeklyScheduled)); ?>
              <div class="bar-column"><div class="bar-pair"><span class="bar bar-admission" style="height: <?= ($weeklyAdmissions[$day] / $maxValue) * 100 ?>%" title="<?= $weeklyAdmissions[$day] ?> completed"></span><span class="bar bar-scheduled" style="height: <?= ($weeklyScheduled[$day] / $maxValue) * 100 ?>%" title="<?= $weeklyScheduled[$day] ?> scheduled"></span></div><small><?= $day ?></small></div>
            <?php endforeach; ?>
          </div>
          <div class="chart-legend"><span><i class="legend-swatch admission"></i>Completed</span><span><i class="legend-swatch scheduled"></i>Scheduled</span></div>
        </article>
        <article class="modern-panel">
          <div class="modern-panel-heading"><div><h2>Appointment Mix</h2><p>Current authorized care network</p></div></div>
          <div class="mix-content"><div class="donut-chart" style="background: conic-gradient(<?= htmlspecialchars($appointmentMixGradient, ENT_QUOTES, 'UTF-8') ?>)"><strong><?= count($appointments) ?></strong><small>total</small></div><div class="mix-legend"><?php foreach ($statusMix as $status => $count): ?><span><i class="mix-dot mix-<?= htmlspecialchars(strtolower($status), ENT_QUOTES, 'UTF-8') ?>"></i><?= htmlspecialchars($status) ?><b><?= $count ?></b></span><?php endforeach; ?><?php if (!$statusMix): ?><span class="muted">No appointments yet</span><?php endif; ?></div></div>
          <div class="modern-info"><i class="bi bi-info-circle"></i> Appointment status is shown here because diagnosis categories are not stored in the current schema.</div>
        </article>
      </div>

      <article id="vitals-monitor" class="modern-panel modern-trend-panel">
        <div class="modern-panel-heading"><div><h2>Cardiac Vitals — 30 Day Trend</h2><p>Average recorded readings across your authorized patients</p></div><div class="range-pills" aria-label="Vitals chart period"><button type="button" class="active" data-doctor-range="30">30D</button><button type="button" data-doctor-range="7">7D</button><button type="button" data-doctor-range="90">90D</button></div></div>
        <?php if ($trendLabels): ?><div class="trend-chart"><canvas id="modernVitalsChart" aria-label="Cardiac vitals trend"></canvas></div><div class="chart-legend"><span><i class="legend-swatch heart"></i>Heart rate</span><span><i class="legend-swatch pressure"></i>Systolic BP</span><span><i class="legend-swatch oxygen"></i>SpO₂</span></div><?php else: ?><p class="modern-empty">No health history is available for the selected patients yet.</p><?php endif; ?>
      </article>
      <article id="priority-queue" class="modern-panel priority-panel">
        <div class="modern-panel-heading"><div><h2>Priority Patient Queue</h2><p>Critical review rule: HR &lt;50 or &gt;120 BPM, or SpO₂ &lt;90%</p></div><div class="queue-controls"><label class="visually-hidden" for="authorized-patient-search">Search authorized patients</label><input id="authorized-patient-search" class="queue-search" type="search" placeholder="Search patients" autocomplete="off"><div class="queue-pills"><button type="button" class="active" data-queue-filter="all">All</button><button type="button" data-queue-filter="critical">Critical</button><button type="button" data-queue-filter="monitoring">Monitoring</button></div></div></div>
        <div class="priority-table-wrap">
          <table class="priority-table"><thead><tr><th>Patient</th><th>Status</th><th>Heart rate</th><th>SpO₂</th><th>Temperature</th><th>BP</th><th>Updated</th></tr></thead><tbody>
            <?php foreach ($patients as $priorityPatient): ?>
            <?php $isCritical = in_array($priorityPatient, $criticalPatients, true); $statusLabel = $isCritical ? 'Critical' : 'Monitoring'; ?>
            <tr data-queue-status="<?= $isCritical ? 'critical' : 'monitoring' ?>" data-patient-name="<?= htmlspecialchars($priorityPatient['fullname'], ENT_QUOTES, 'UTF-8') ?>"><td><strong><?= htmlspecialchars($priorityPatient['fullname']) ?></strong><small>#P-<?= str_pad((string) $priorityPatient['id'], 4, '0', STR_PAD_LEFT) ?></small></td><td><span class="queue-status <?= $isCritical ? 'critical' : 'monitoring' ?>"><?= $statusLabel ?></span></td><td class="<?= $isCritical ? 'danger-value' : 'good-value' ?>"><?= $priorityPatient['heart_rate'] !== null ? htmlspecialchars((string) $priorityPatient['heart_rate']) . ' bpm' : '—' ?></td><td><?= $priorityPatient['spo2'] !== null ? htmlspecialchars((string) $priorityPatient['spo2']) . '%' : '—' ?></td><td><?= $priorityPatient['temperature'] !== null ? htmlspecialchars((string) $priorityPatient['temperature']) . '°C' : '—' ?></td><td><?= $priorityPatient['blood_pressure_sys'] !== null && $priorityPatient['blood_pressure_dia'] !== null ? htmlspecialchars((string) $priorityPatient['blood_pressure_sys']) . '/' . htmlspecialchars((string) $priorityPatient['blood_pressure_dia']) : '—' ?></td><td class="updated-value"><?= $priorityPatient['recorded_at'] ? htmlspecialchars(date('M j, H:i', strtotime($priorityPatient['recorded_at']))) : '—' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$patients): ?><tr><td colspan="7" class="modern-empty">No authorized patients are available.</td></tr><?php endif; ?>
          </tbody></table>
        </div>
      </article>
    </section>

    <ul class="nav nav-tabs mb-4" id="doctorDashboardTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard-tab-pane" type="button" role="tab" aria-controls="dashboard-tab-pane" aria-selected="true">
          <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="doctors-tab" data-bs-toggle="tab" data-bs-target="#doctors-tab-pane" type="button" role="tab" aria-controls="doctors-tab-pane" aria-selected="false">
          <i class="bi bi-hospital me-1"></i>Doctors
        </button>
      </li>
      <?php if ($canAccessAppointments): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments-tab-pane" type="button" role="tab" aria-controls="appointments-tab-pane" aria-selected="false">
          <i class="bi bi-calendar-check me-1"></i>Appointments
          <span class="badge bg-info ms-1"><?= count($appointments) ?></span>
        </button>
      </li>
      <?php endif; ?>
    </ul>

    <div class="tab-content" id="doctorDashboardTabContent">
      <div class="tab-pane fade" id="doctors-tab-pane" role="tabpanel" aria-labelledby="doctors-tab" tabindex="0">

    <!-- Doctors Directory -->
    <div class="card mb-5">
      <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-hospital"></i> Doctors by Department</h5>
        <span class="badge bg-light text-dark"><?= count($doctorsByDepartment) ?> Departments</span>
      </div>
      <div class="card-body">
        <?php if (empty($doctorsByDepartment)): ?>
          <div class="alert alert-info mb-0">No doctors are available yet.</div>
        <?php else: ?>
          <ul class="nav nav-pills mb-4" id="departmentTabs" role="tablist">
            <?php $departmentIndex = 0; ?>
            <?php foreach ($doctorsByDepartment as $departmentName => $departmentDoctors): ?>
              <?php $departmentTabId = 'department-tab-' . $departmentIndex; ?>
              <li class="nav-item" role="presentation">
                <button class="nav-link <?= $departmentIndex === 0 ? 'active' : '' ?>" id="<?= $departmentTabId ?>" data-bs-toggle="pill" data-bs-target="#<?= $departmentTabId ?>-pane" type="button" role="tab" aria-controls="<?= $departmentTabId ?>-pane" aria-selected="<?= $departmentIndex === 0 ? 'true' : 'false' ?>">
                  <i class="bi bi-building me-1"></i><?= htmlspecialchars($departmentName) ?>
                  <span class="badge bg-info ms-1"><?= count($departmentDoctors) ?></span>
                </button>
              </li>
              <?php $departmentIndex++; ?>
            <?php endforeach; ?>

          </ul>
          <div class="tab-content" id="departmentTabContent">
            <?php $departmentIndex = 0; ?>
            <?php foreach ($doctorsByDepartment as $departmentName => $departmentDoctors): ?>
              <?php $departmentTabId = 'department-tab-' . $departmentIndex; ?>
              <div class="tab-pane fade <?= $departmentIndex === 0 ? 'show active' : '' ?>" id="<?= $departmentTabId ?>-pane" role="tabpanel" aria-labelledby="<?= $departmentTabId ?>" tabindex="0">
                <div class="card department-card border-info">
                  <div class="card-header bg-light fw-bold">
                    <i class="bi bi-building me-1"></i><?= htmlspecialchars($departmentName) ?> Department
                  </div>
                  <div class="list-group list-group-flush">
                    <?php foreach ($departmentDoctors as $directoryDoctor): ?>
                      <div class="list-group-item">
                        <strong><?= htmlspecialchars($directoryDoctor['fullname']) ?></strong>
                        <div class="small text-muted">
                          <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($directoryDoctor['email']) ?>
                        </div>
                        <div class="small text-muted">
                          <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($directoryDoctor['phone']) ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <?php $departmentIndex++; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

      </div>

      <div class="tab-pane fade show active" id="dashboard-tab-pane" role="tabpanel" aria-labelledby="dashboard-tab" tabindex="0">

    <!-- Patients Section -->
    <div class="card mb-5">
      <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-people-fill"></i> Your Patients & Health Data</h5>
        <span class="badge bg-light text-dark"><?= count($patients) ?> Patients</span>
      </div>
      <div class="card-body">
        <?php if (empty($patients)): ?>
          <div class="alert alert-info">No patients found yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Contact</th>
                  <th>Health Metrics</th>
                  <th>Model estimates</th>
                  <th>Last Updated</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($patients as $p): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="profile-icon me-3">
                          <?= strtoupper(substr($p['fullname'], 0, 1)) ?>
                        </div>
                        <div>
                          <strong><?= htmlspecialchars($p['fullname']) ?></strong>
                          <div class="text-muted small">Age: <?= htmlspecialchars($p['age']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div><?= htmlspecialchars($p['email']) ?></div>
                      <div class="text-muted small"><?= htmlspecialchars($p['phone']) ?></div>
                    </td>
                    <td>
                      <div class="d-flex flex-wrap gap-2">
                        <?php if ($p['heart_rate']): ?>
                          <span class="badge bg-danger">HR: <?= $p['heart_rate'] ?? 'N/A' ?> BPM</span>
                        <?php endif; ?>
                        <?php if ($p['spo2']): ?>
                          <span class="badge bg-info">SpO₂: <?= $p['spo2'] ?? 'N/A' ?>%</span>
                        <?php endif; ?>
                        <?php if ($p['temperature']): ?>
                          <span class="badge bg-warning">Temp: <?= $p['temperature'] ?? 'N/A' ?>°C</span>
                        <?php endif; ?>
                        <?php if (isset($p['blood_pressure_sys'], $p['blood_pressure_dia'])): ?>
                          <span class="badge bg-secondary">BP: <?= "{$p['blood_pressure_sys']}/{$p['blood_pressure_dia']}" ?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($p['ml_predictions'])): ?>
                        <div class="small text-muted mb-1">Experimental only</div>
                        <?php foreach ($p['ml_predictions'] as $estimate): ?>
                          <div><strong><?= htmlspecialchars(str_replace('_', ' ', $estimate['target_name'])) ?>:</strong>
                            <?= htmlspecialchars((string) $estimate['predicted_value']) ?> <?= htmlspecialchars(['heart_rate'=>'BPM','spo2'=>'%','temperature'=>'°C','blood_pressure_sys'=>'mmHg','blood_pressure_dia'=>'mmHg'][$estimate['target_name']] ?? '') ?> <span class="text-muted">predicted</span><?php if ($estimate['actual_value'] !== null): ?> · actual <?= htmlspecialchars((string) $estimate['actual_value']) ?><?php endif; ?></div>
                        <?php endforeach; ?>
                        <div class="small text-muted">Created <?= htmlspecialchars($p['ml_predictions'][0]['prediction_timestamp']) ?> · target <?= htmlspecialchars($p['ml_predictions'][0]['target_timestamp'] ?? 'unspecified') ?> · <?= htmlspecialchars($p['ml_predictions'][0]['model_version']) ?></div>
                      <?php else: ?><span class="text-muted small">No estimate</span><?php endif; ?>
                    </td>
                    <td>
                      <?= $p['recorded_at'] ? date("M j, Y H:i", strtotime($p['recorded_at'])) : 'N/A' ?>
                      <?php if (!empty($p['health_history'])): ?>
                        <button class="btn btn-sm btn-outline-primary trend-btn ms-2" 
                                data-bs-toggle="modal" 
                                data-bs-target="#healthTrendsModal"
                                data-patient-id="<?= $p['id'] ?>"
                                data-patient-name="<?= htmlspecialchars($p['fullname']) ?>">
                          <i class="bi bi-graph-up"></i> Trends
                        </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

      </div>

      <div class="tab-pane fade" id="appointments-tab-pane" role="tabpanel" aria-labelledby="appointments-tab" tabindex="0">

    <!-- Appointments Section -->
    <div class="card">
      <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Patient Appointments</h5>
        <span class="badge bg-light text-dark"><?= count($appointments) ?> Appointments</span>
      </div>
      <div class="card-body">
        <?php if (empty($appointments)): ?>
          <div class="alert alert-info">No appointments scheduled yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Latest Vitals</th>
                  <th>Date/Time</th>
                  <th>Status</th>
                  <th>Notes</th>
                  <th>Prescriptions</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($appointments as $appt): ?>
                  <?php $authorizedPatient = $patientsById[(int) $appt['patient_id']] ?? null; ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="profile-icon me-3" style="background-color: #6c757d;">
                          <?= strtoupper(substr($appt['patient_name'], 0, 1)) ?>
                        </div>
                        <div>
                          <strong><?= htmlspecialchars($appt['patient_name']) ?></strong>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="doctor-vitals-summary">
                        <span><small>HR</small><strong><?= $appt['latest_heart_rate'] !== null ? htmlspecialchars((string) $appt['latest_heart_rate']) . ' bpm' : '—' ?></strong></span>
                        <span><small>SpO₂</small><strong><?= $appt['latest_spo2'] !== null ? htmlspecialchars((string) $appt['latest_spo2']) . '%' : '—' ?></strong></span>
                        <span><small>Temp</small><strong><?= $appt['latest_temperature'] !== null ? htmlspecialchars((string) $appt['latest_temperature']) . '°C' : '—' ?></strong></span>
                        <span><small>BP</small><strong><?= $appt['latest_blood_pressure_sys'] !== null && $appt['latest_blood_pressure_dia'] !== null ? htmlspecialchars((string) $appt['latest_blood_pressure_sys']) . '/' . htmlspecialchars((string) $appt['latest_blood_pressure_dia']) : '—' ?></strong></span>
                      </div>
                      <?php if ($appt['vitals_recorded_at']): ?><small class="doctor-vitals-updated">Recorded <?= htmlspecialchars(date('M j, Y H:i', strtotime($appt['vitals_recorded_at']))) ?></small><?php endif; ?>
                      <?php if (!empty($authorizedPatient['health_history'])): ?>
                        <button type="button" class="doctor-vitals-history" data-bs-toggle="modal" data-bs-target="#healthTrendsModal" data-patient-id="<?= (int) $appt['patient_id'] ?>" data-patient-name="<?= htmlspecialchars($appt['patient_name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-graph-up"></i> History</button>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= date("M j, Y", strtotime($appt['appointment_date'])) ?>
                      <div class="text-muted small"><?= date("H:i", strtotime($appt['appointment_time'])) ?></div>
                    </td>
                    <td>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="doctor_csrf" value="<?= htmlspecialchars($_SESSION['doctor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                        <select name="status" class="form-select status-dropdown" onchange="this.form.submit()">
                          <option value="pending" <?= $appt['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                          <option value="completed" <?= $appt['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                          <option value="cancelled" <?= $appt['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                      </form>
                    </td>
                    <td>
                      <form method="POST">
                        <input type="hidden" name="doctor_csrf" value="<?= htmlspecialchars($_SESSION['doctor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                        <div class="input-group">
                          <input type="text" name="notes" class="form-control form-control-sm" value="<?= htmlspecialchars($appt['notes'] ?? '') ?>" placeholder="Notes">
                          <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-save"></i>
                          </button>
                        </div>
                      </form>
                    </td>
                    <td>
                      <?php if (!empty($prescriptionsByAppointment[$appt['id']])): ?>
                        <div class="prescription-list">
                          <?php foreach ($prescriptionsByAppointment[$appt['id']] as $prescription): ?>
                            <div class="prescription-card p-2 mb-2 bg-light small">
                              <div><?= nl2br(htmlspecialchars($prescription['prescription'])) ?></div>
                              <?php if (!empty($prescription['medication'])): ?>
                                <div class="text-muted mt-1">Medication: <?= htmlspecialchars($prescription['medication']) ?></div>
                              <?php endif; ?>
                              <form method="POST" class="mt-2" onsubmit="return confirm('Delete this prescription?');">
                                <input type="hidden" name="doctor_csrf" value="<?= htmlspecialchars($_SESSION['doctor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="delete_prescription" value="1">
                                <input type="hidden" name="prescription_id" value="<?= (int) $prescription['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                  <i class="bi bi-trash"></i> Delete
                                </button>
                              </form>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted small">None</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                              data-bs-target="#addPrescriptionModal" 
                              data-patient-id="<?= $appt['patient_id'] ?>"
                              data-patient-name="<?= htmlspecialchars($appt['patient_name']) ?>"
                              data-appointment-id="<?= $appt['id'] ?>">
                        <i class="bi bi-prescription"></i> Add Rx
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
      </div>
    </div>
  </div>

  <!-- View Profile Modal -->
  <div class="modal fade" id="viewProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Doctor Profile</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="text-center mb-4">
            <div class="profile-icon mx-auto" style="width: 100px; height: 100px; font-size: 3rem;">
              <?= strtoupper(substr($doctorDisplayName, 0, 1)) ?>
            </div>
            <h3 class="mt-3">Dr. <?= htmlspecialchars($doctorDisplayName) ?></h3>
            <p class="text-muted"><i class="bi bi-building"></i> <?= htmlspecialchars($doctor['department']) ?> Department</p>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6><i class="bi bi-envelope"></i> Email</h6>
                  <p><?= htmlspecialchars($doctor['email']) ?></p>
                </div>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6><i class="bi bi-telephone"></i> Phone</h6>
                  <p><?= htmlspecialchars($doctor['phone']) ?></p>
                </div>
              </div>
            </div>
          </div>
          
          <div class="card">
            <div class="card-body">
              <h6><i class="bi bi-person-badge"></i> Doctor ID</h6>
              <p><?= htmlspecialchars($doctor['id']) ?></p>
            </div>
          </div>

          <div class="card mt-3">
            <div class="card-body d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="bi bi-file-earmark-check"></i> Application status</h6>
              <span class="badge bg-<?= $applicationStatus === 'approved' ? 'success' : ($applicationStatus === 'rejected' ? 'danger' : 'warning text-dark') ?>">
                <?= htmlspecialchars($applicationStatusLabel) ?>
              </span>
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

  <!-- Edit Profile Modal -->
  <div class="modal fade" id="updateProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="POST" class="modal-content">
        <input type="hidden" name="doctor_csrf" value="<?= htmlspecialchars($_SESSION['doctor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Update Profile</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="update_profile" value="1" />
          <div class="mb-3">
            <label class="form-label">Full Name</label>
              <input type="text" name="fullname" class="form-control" maxlength="100" value="<?= htmlspecialchars($doctor['fullname']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" maxlength="100" value="<?= htmlspecialchars($doctor['email']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" maxlength="20" value="<?= htmlspecialchars($doctor['phone']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Department</label>
              <input type="text" name="department" class="form-control" maxlength="50" value="<?= htmlspecialchars($doctor['department']) ?>" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Add Prescription Modal -->
  <div class="modal fade" id="addPrescriptionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <form method="POST" class="modal-content">
        <input type="hidden" name="doctor_csrf" value="<?= htmlspecialchars($_SESSION['doctor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Add Prescription for <span id="patientName"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="add_prescription" value="1" />
          <input type="hidden" id="patientId" name="patient_id" value="" />
          <input type="hidden" id="appointmentId" name="appointment_id" value="" />
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Medication</label>
                  <input type="text" name="medication" class="form-control" maxlength="100" placeholder="Enter medication name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Dosage</label>
                  <input type="text" name="dosage" class="form-control" maxlength="50" placeholder="e.g., 500mg, 1 tablet">
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Instructions</label>
            <textarea class="form-control" name="instructions" rows="2" 
                      placeholder="Usage instructions (e.g., Take twice daily after meals)"></textarea>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Full Prescription Details</label>
            <textarea class="form-control" name="prescription" rows="5" required 
                      placeholder="Enter complete prescription details including duration, refills, etc."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-prescription"></i> Save Prescription
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Health Trends Modal -->
  <div class="modal fade" id="healthTrendsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Health Trends for <span id="trendPatientName"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="noDataMessage" class="alert alert-info d-none">
            No health data available for this patient.
          </div>
          <div id="chartsContainer">
            <div class="row">
              <div class="col-md-6">
                <div class="card mb-4">
                  <div class="card-header bg-danger text-white">
                    <h6 class="mb-0">Heart Rate (BPM)</h6>
                  </div>
                  <div class="card-body">
                    <div class="chart-container">
                      <canvas id="heartRateChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card mb-4">
                  <div class="card-header bg-info text-white">
                    <h6 class="mb-0">SpO₂ (%)</h6>
                  </div>
                  <div class="card-body">
                    <div class="chart-container">
                      <canvas id="spo2Chart"></canvas>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card mb-4">
                  <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0">Temperature (°C)</h6>
                  </div>
                  <div class="card-body">
                    <div class="chart-container">
                      <canvas id="tempChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card mb-4">
                  <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0">Blood Pressure (mmHg)</h6>
                  </div>
                  <div class="card-body">
                    <div class="chart-container">
                      <canvas id="bpChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead class="table-light">
                  <tr>
                    <th>Date/Time</th>
                    <th>Heart Rate</th>
                    <th>SpO₂</th>
                    <th>Temperature</th>
                    <th>Blood Pressure</th>
                  </tr>
                </thead>
                <tbody id="healthMetricsTableBody">
                  <!-- Data inserted by JavaScript -->
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Set patient info when prescription modal is shown
    document.getElementById('addPrescriptionModal').addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      document.getElementById('patientId').value = button.getAttribute('data-patient-id');
      document.getElementById('patientName').textContent = button.getAttribute('data-patient-name');
      document.getElementById('appointmentId').value = button.getAttribute('data-appointment-id');
    });

    // Add hover effects to health metric cards
    document.querySelectorAll('.health-metric-card').forEach(card => {
      card.addEventListener('mouseenter', () => {
        card.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
      });
      card.addEventListener('mouseleave', () => {
        card.style.boxShadow = '';
      });
    });

    // Health Trends Modal Handler
    document.getElementById('healthTrendsModal').addEventListener('show.bs.modal', function(event) {
      const button = event.relatedTarget;
      const patientId = button.getAttribute('data-patient-id');
      const patientName = button.getAttribute('data-patient-name');
      
      document.getElementById('trendPatientName').textContent = patientName;
      
      // Find the patient data
      const patients = <?= json_encode($patients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
      const patient = patients.find(p => parseInt(p.id) === parseInt(patientId));
      
      if (!patient || !patient.health_history || patient.health_history.length === 0) {
        document.getElementById('noDataMessage').classList.remove('d-none');
        document.getElementById('chartsContainer').classList.add('d-none');
        document.getElementById('healthMetricsTableBody').innerHTML = 
          '<tr><td colspan="5" class="text-center">No health data available</td></tr>';
        return;
      } else {
        document.getElementById('noDataMessage').classList.add('d-none');
        document.getElementById('chartsContainer').classList.remove('d-none');
      }
      
      const healthHistory = patient.health_history;
      
      // Prepare data
      const labels = healthHistory.map(entry => new Date(entry.recorded_at).toLocaleString());
      const heartRates = healthHistory.map(entry => entry.heart_rate ?? null);
      const spo2s = healthHistory.map(entry => entry.spo2 ?? null);
      const temps = healthHistory.map(entry => entry.temperature ?? null);
      const bpSys = healthHistory.map(entry => entry.blood_pressure_sys ?? null);
      const bpDia = healthHistory.map(entry => entry.blood_pressure_dia ?? null);
      
      // Update table
      const tableBody = document.getElementById('healthMetricsTableBody');
      tableBody.innerHTML = '';
      healthHistory.forEach(entry => {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${new Date(entry.recorded_at).toLocaleString()}</td>
          <td>${entry.heart_rate ?? 'N/A'}</td>
          <td>${entry.spo2 ?? 'N/A'}</td>
          <td>${entry.temperature ?? 'N/A'}</td>
          <td>${entry.blood_pressure_sys !== null && entry.blood_pressure_dia !== null
              ? `${entry.blood_pressure_sys}/${entry.blood_pressure_dia}` 
              : 'N/A'}</td>
        `;
        tableBody.appendChild(row);
      });
      
      // Initialize/Update charts
      createChart('heartRateChart', 'Heart Rate', labels, heartRates, '#dc3545');
      createChart('spo2Chart', 'SpO₂', labels, spo2s, '#17a2b8');
      createChart('tempChart', 'Temperature', labels, temps, '#ffc107');
      createBPChart('bpChart', labels, bpSys, bpDia);
    });

    function createChart(canvasId, label, labels, data, color) {
      const ctx = document.getElementById(canvasId).getContext('2d');
      if (window[canvasId + 'Chart']) window[canvasId + 'Chart'].destroy();
      
      window[canvasId + 'Chart'] = new Chart(ctx, {
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
            y: { beginAtZero: false }
          }
        }
      });
    }

    function createBPChart(canvasId, labels, sysData, diaData) {
      const ctx = document.getElementById(canvasId).getContext('2d');
      if (window.bpChart) window.bpChart.destroy();
      
      window.bpChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Systolic',
              data: sysData,
              borderColor: '#6c757d',
              backgroundColor: '#6c757d33',
              tension: 0.3
            },
            {
              label: 'Diastolic',
              data: diaData,
              borderColor: '#495057',
              backgroundColor: '#49505733',
              tension: 0.3
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: false }
          }
        }
      });
    }

    const modernVitalsCanvas = document.getElementById('modernVitalsChart');
    if (modernVitalsCanvas) {
      new Chart(modernVitalsCanvas, {
        type: 'line',
        data: {
          labels: <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
          datasets: [
            { label: 'Heart rate', data: <?= json_encode($trendData['heart_rate'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, borderColor: '#43d6a0', backgroundColor: 'rgba(67,214,160,.12)', tension: .35, spanGaps: true, pointRadius: 2, fill: true },
            { label: 'Systolic BP', data: <?= json_encode($trendData['blood_pressure_sys'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, borderColor: '#b49aff', backgroundColor: 'transparent', tension: .35, spanGaps: true, pointRadius: 2 },
            { label: 'SpO2', data: <?= json_encode($trendData['spo2'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, borderColor: '#ff8a35', backgroundColor: 'transparent', tension: .35, spanGaps: true, pointRadius: 2 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#777b83', maxTicksLimit: 7, font: { size: 10 } } },
            y: { grid: { color: 'rgba(119,123,131,.12)' }, ticks: { color: '#777b83', font: { size: 10 } } }
          }
        }
      });
      const chart = Chart.getChart(modernVitalsCanvas);
      window.addEventListener('medconnect:themechange', function (event) {
        const color = event.detail.dark ? '#777b83' : '#52646b';
        const grid = event.detail.dark ? 'rgba(119,123,131,.12)' : 'rgba(82,100,107,.15)';
        chart.options.scales.x.ticks.color = color;
        chart.options.scales.y.ticks.color = color;
        chart.options.scales.y.grid.color = grid;
        chart.update();
      });
      const trendDates = <?= json_encode($trendDates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const trendValues = <?= json_encode($trendData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      function updateDoctorRange(days) {
        const cutoff = new Date();
        cutoff.setDate(cutoff.getDate() - days);
        const indexes = trendDates.map((date, index) => ({ date: new Date(date + 'T00:00:00'), index })).filter((item) => item.date >= cutoff).map((item) => item.index);
        chart.data.labels = indexes.map((index) => <?= json_encode($trendLabels) ?>[index]);
        chart.data.datasets.forEach((dataset, datasetIndex) => {
          const key = ['heart_rate', 'blood_pressure_sys', 'spo2'][datasetIndex];
          dataset.data = indexes.map((index) => trendValues[key][index]);
        });
        chart.update();
      }
      document.querySelectorAll('[data-doctor-range]').forEach((button) => button.addEventListener('click', () => {
        document.querySelectorAll('[data-doctor-range]').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        updateDoctorRange(Number(button.dataset.doctorRange));
      }));
      updateDoctorRange(30);
    }

    document.querySelectorAll('[data-queue-filter]').forEach(function (button) {
      button.addEventListener('click', function () {
        document.querySelectorAll('[data-queue-filter]').forEach(function (item) { item.classList.remove('active'); });
        button.classList.add('active');
        const filter = button.dataset.queueFilter;
        applyQueueFilters();
      });
    });
    const patientSearch = document.getElementById('authorized-patient-search');
    function applyQueueFilters() {
      const filter = document.querySelector('[data-queue-filter].active')?.dataset.queueFilter || 'all';
      const query = (patientSearch?.value || '').trim().toLocaleLowerCase();
      document.querySelectorAll('[data-queue-status]').forEach(function (row) {
        const matchesStatus = filter === 'all' || row.dataset.queueStatus === filter;
        const matchesName = !query || row.dataset.patientName.toLocaleLowerCase().includes(query);
        row.hidden = !(matchesStatus && matchesName);
      });
    }
    if (patientSearch) patientSearch.addEventListener('input', applyQueueFilters);
  </script>
  <aside class="health-assistant" aria-label="MedConnect Health Assistant">
    <div class="health-assistant-header"><div><span class="health-assistant-kicker">MedConnect AI</span><h2>Health Assistant</h2></div><span class="health-assistant-status" aria-label="Ready"></span><button type="button" class="health-assistant-close" id="healthAssistantClose" aria-label="Close health assistant">×</button></div>
    <label class="health-assistant-patient">Patient readings
      <select id="healthAssistantPatient" aria-label="Choose an assigned patient">
        <option value="">Knowledge questions only</option>
        <?php foreach ($patients as $assistantPatient): ?>
          <option value="<?= (int)$assistantPatient['id'] ?>"><?= htmlspecialchars($assistantPatient['fullname'], ENT_QUOTES, 'UTF-8') ?> (ID <?= (int)$assistantPatient['id'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="health-assistant-messages" id="healthAssistantMessages" aria-live="polite"><div class="health-assistant-message model">Ask about approved reference documents, or choose an assigned patient to ask about stored readings. Informational support only; not a diagnosis.</div></div>
    <form id="healthAssistantForm" class="health-assistant-form" data-role="doctor" data-chat-endpoint="gemini-chat.php" data-csrf="<?= htmlspecialchars($_SESSION['assistant_csrf'] ??= bin2hex(random_bytes(32)), ENT_QUOTES, 'UTF-8') ?>"><label class="visually-hidden" for="healthAssistantInput">Ask the health assistant</label><input id="healthAssistantInput" name="question" maxlength="2000" autocomplete="off" placeholder="Ask a health question..." required><button type="submit" aria-label="Send question">Send</button></form>
    <p class="health-assistant-note">For emergencies, use established clinical and emergency procedures.</p>
  </aside>
  <button type="button" class="health-assistant-launcher" id="healthAssistantLauncher" aria-label="Open health assistant">✚ <span>Health assistant</span></button>
  <link rel="stylesheet" href="css/health-assistant.css">
  <script src="js/health-assistant.js"></script>
  <script src="js/design-switch.js"></script>
</body>
</html>

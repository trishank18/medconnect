<?php
session_start();
require_once __DIR__ . '/../backend/includes/db_connection.php';

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin-login.html');
    exit();
}

$statusFilter = $_GET['status'] ?? 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign_appointments'])) {
  $appointmentDate = $_POST['appointment_date'] ?? '';
  $appointmentTime = $_POST['appointment_time'] ?? '';
  $schedule = DateTime::createFromFormat('Y-m-d H:i', $appointmentDate . ' ' . $appointmentTime);
  $scheduleErrors = DateTime::getLastErrors();
  $hasScheduleErrors = is_array($scheduleErrors) && ($scheduleErrors['warning_count'] > 0 || $scheduleErrors['error_count'] > 0);

  if (!$schedule || $hasScheduleErrors || $schedule->getTimestamp() <= time()) {
    header('Location: admin-dashboard.php?error=invalid_schedule');
    exit();
  }

  try {
    $approvedDoctors = $conn->query("SELECT id FROM doctors WHERE verification_status = 'approved' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $patientIds = $conn->query('SELECT id FROM patients ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    if (!$approvedDoctors || !$patientIds) {
      header('Location: admin-dashboard.php?error=missing_doctors_or_patients');
      exit();
    }

    $conn->beginTransaction();
    $existingPatientsStmt = $conn->query("SELECT DISTINCT patient_id FROM appointments WHERE status <> 'cancelled'");
    $patientsWithAppointments = array_fill_keys(array_map('intval', $existingPatientsStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    $assignedDoctorsStmt = $conn->query("SELECT DISTINCT doctor_id FROM appointments WHERE status <> 'cancelled'");
    $assignedDoctors = array_fill_keys(array_map('intval', $assignedDoctorsStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    $insertStmt = $conn->prepare('INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, notes) VALUES (:patient_id, :doctor_id, :appointment_date, :appointment_time, :notes)');
    $patientDoctorStmt = $conn->prepare('UPDATE patients SET doctor_id = :doctor_id WHERE id = :patient_id AND doctor_id IS NULL');
    $createdCount = 0;
    $doctorIndex = 0;
    $slot = clone $schedule;

    foreach ($patientIds as $patientId) {
      if (isset($patientsWithAppointments[(int) $patientId])) {
        continue;
      }

      $doctorId = (int) $approvedDoctors[$doctorIndex % count($approvedDoctors)];
      $insertStmt->execute([
        ':patient_id' => $patientId,
        ':doctor_id' => $doctorId,
        ':appointment_date' => $slot->format('Y-m-d'),
        ':appointment_time' => $slot->format('H:i:s'),
        ':notes' => 'Scheduled by administrator for patient assignment.'
      ]);
      $patientDoctorStmt->execute([':doctor_id' => $doctorId, ':patient_id' => $patientId]);
      $patientsWithAppointments[(int) $patientId] = true;
      $assignedDoctors[$doctorId] = true;
      $createdCount++;
      $doctorIndex++;
      $slot->modify('+30 minutes');
    }

    foreach ($approvedDoctors as $doctorId) {
      $doctorId = (int) $doctorId;
      if (isset($assignedDoctors[$doctorId])) {
        continue;
      }

      $patientId = (int) $patientIds[$doctorIndex % count($patientIds)];
      $insertStmt->execute([
        ':patient_id' => $patientId,
        ':doctor_id' => $doctorId,
        ':appointment_date' => $slot->format('Y-m-d'),
        ':appointment_time' => $slot->format('H:i:s'),
        ':notes' => 'Scheduled by administrator to ensure doctor coverage.'
      ]);
      $assignedDoctors[$doctorId] = true;
      $createdCount++;
      $doctorIndex++;
      $slot->modify('+30 minutes');
    }

    $conn->commit();
    header('Location: admin-dashboard.php?bulk_appointments=' . $createdCount);
    exit();
  } catch (PDOException $exception) {
    if ($conn->inTransaction()) {
      $conn->rollBack();
    }
    header('Location: admin-dashboard.php?error=bulk_schedule_failed');
    exit();
  }
}

$query = 'SELECT id, fullname, email, phone, department, username, verification_status, created_at FROM doctors';
$params = [];
if ($statusFilter !== 'all') {
    $query .= ' WHERE verification_status = :status';
    $params[':status'] = $statusFilter;
}
$query .= ' ORDER BY created_at DESC';
$stmt = $conn->prepare($query);
$stmt->execute($params);
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
$counts = $conn->query('SELECT verification_status, COUNT(*) AS total FROM doctors GROUP BY verification_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$patientCount = (int) $conn->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$patients = $conn->query('SELECT id, fullname, email, username FROM patients ORDER BY fullname, id')->fetchAll(PDO::FETCH_ASSOC);
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="MedConnect administrator dashboard" />
  <title>Admin Dashboard - MedConnect</title>
  <link rel="stylesheet" href="../frontend/css/style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --mc-primary: #0d9488;
      --mc-primary-dark: #0f766e;
      --mc-primary-light: #ccfbf1;
      --mc-accent: #6366f1;
      --mc-bg: #f8fafc;
      --mc-surface: #ffffff;
      --mc-border: #e2e8f0;
      --mc-text: #0f172a;
      --mc-text-muted: #64748b;
      --mc-success: #10b981;
      --mc-success-bg: #ecfdf5;
      --mc-warning: #f59e0b;
      --mc-warning-bg: #fffbeb;
      --mc-danger: #ef4444;
      --mc-danger-bg: #fef2f2;
      --mc-radius: 16px;
      --mc-radius-sm: 10px;
      --mc-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 4px 16px rgba(15, 23, 42, 0.04);
      --mc-shadow-lg: 0 4px 24px rgba(15, 23, 42, 0.08);
    }

    * { box-sizing: border-box; }

    body.admin-page {
      margin: 0;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--mc-bg);
      color: var(--mc-text);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }

    /* Topbar */
    .topbar {
      position: sticky;
      top: 0;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 2rem;
      height: 68px;
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--mc-border);
    }

    .brand-mark {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-weight: 800;
      font-size: 1.15rem;
      color: var(--mc-text);
      text-decoration: none;
      letter-spacing: -0.02em;
    }

    .brand-mark span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, var(--mc-primary), var(--mc-accent));
      color: #fff;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 800;
      letter-spacing: 0.02em;
    }

    .topbar nav {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .topbar nav a {
      padding: 0.5rem 0.9rem;
      border-radius: 8px;
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--mc-text-muted);
      text-decoration: none;
      transition: all 0.15s ease;
    }

    .topbar nav a:hover {
      background: var(--mc-bg);
      color: var(--mc-text);
    }

    /* Shell */
    .admin-shell {
      max-width: 1200px;
      margin: 0 auto;
      padding: 2rem 2rem 4rem;
    }

    /* Hero */
    .admin-hero {
      position: relative;
      border-radius: var(--mc-radius);
      overflow: hidden;
      margin-bottom: 2rem;
      box-shadow: var(--mc-shadow-lg);
      min-height: 220px;
      display: flex;
      align-items: flex-end;
    }

    .admin-hero img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      z-index: 0;
    }

    .admin-hero::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(13, 148, 136, 0.92) 0%, rgba(15, 23, 42, 0.85) 100%);
      z-index: 1;
    }

    .admin-hero-copy {
      position: relative;
      z-index: 2;
      padding: 2rem 2.25rem;
      color: #fff;
    }

    .admin-hero-copy .eyebrow {
      color: rgba(255, 255, 255, 0.8);
    }

    .admin-hero-copy h1 {
      margin: 0.25rem 0 0;
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .eyebrow {
      margin: 0;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--mc-primary-dark);
    }

    /* Page heading */
    .page-heading {
      margin-bottom: 1.5rem;
    }

    .page-heading h2 {
      margin: 0.25rem 0 0.35rem;
      font-size: 1.5rem;
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .page-heading p {
      margin: 0;
      color: var(--mc-text-muted);
      max-width: 620px;
    }

    /* Alerts */
    .admin-alerts { margin-bottom: 1.5rem; }

    .success-message,
    .error-message {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      margin: 0 0 0.6rem;
      padding: 0.85rem 1.1rem;
      border-radius: var(--mc-radius-sm);
      font-size: 0.9rem;
      font-weight: 500;
      border: 1px solid transparent;
    }

    .success-message {
      background: var(--mc-success-bg);
      color: #065f46;
      border-color: #a7f3d0;
    }

    .success-message::before {
      content: '✓';
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 20px;
      height: 20px;
      background: var(--mc-success);
      color: #fff;
      border-radius: 50%;
      font-size: 0.7rem;
      font-weight: 800;
      flex-shrink: 0;
    }

    .error-message {
      background: var(--mc-danger-bg);
      color: #991b1b;
      border-color: #fecaca;
    }

    .error-message::before {
      content: '!';
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 20px;
      height: 20px;
      background: var(--mc-danger);
      color: #fff;
      border-radius: 50%;
      font-size: 0.75rem;
      font-weight: 800;
      flex-shrink: 0;
    }

    /* Metrics */
    .admin-metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .admin-metric {
      background: var(--mc-surface);
      border: 1px solid var(--mc-border);
      border-radius: var(--mc-radius);
      padding: 1.35rem 1.5rem;
      box-shadow: var(--mc-shadow);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      position: relative;
      overflow: hidden;
    }

    .admin-metric::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--mc-primary), var(--mc-accent));
      opacity: 0;
      transition: opacity 0.2s ease;
    }

    .admin-metric:hover {
      transform: translateY(-2px);
      box-shadow: var(--mc-shadow-lg);
    }

    .admin-metric:hover::before { opacity: 1; }

    .admin-metric span {
      display: block;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--mc-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .admin-metric strong {
      display: block;
      margin: 0.4rem 0 0.15rem;
      font-size: 2.1rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      color: var(--mc-text);
      line-height: 1.1;
    }

    .admin-metric small {
      font-size: 0.8rem;
      color: var(--mc-text-muted);
    }

    /* Action cards */
    .admin-actions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }

    .admin-action-card {
      background: var(--mc-surface);
      border: 1px solid var(--mc-border);
      border-radius: var(--mc-radius);
      padding: 1.75rem;
      box-shadow: var(--mc-shadow);
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .admin-section-heading {
      display: flex;
      gap: 1rem;
      align-items: flex-start;
    }

    .admin-section-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--mc-primary-light), #e0e7ff);
      color: var(--mc-primary-dark);
      font-weight: 800;
      font-size: 0.75rem;
      flex-shrink: 0;
    }

    .admin-action-card h2 {
      margin: 0 0 0.25rem;
      font-size: 1.1rem;
      font-weight: 700;
      letter-spacing: -0.01em;
    }

    .admin-action-card p {
      margin: 0;
      font-size: 0.875rem;
      color: var(--mc-text-muted);
    }

    .admin-danger-card {
      border-color: #fecaca;
    }

    .admin-danger-card .admin-section-icon {
      background: var(--mc-danger-bg);
      color: #b91c1c;
    }

    .admin-delete-form {
      display: grid;
      gap: 0.5rem;
    }

    .admin-delete-form label {
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--mc-text-muted);
    }

    .admin-delete-controls {
      display: flex;
      align-items: stretch;
      gap: 0.75rem;
    }

    .admin-delete-controls select {
      min-width: 0;
      flex: 1;
      padding: 0.7rem 0.8rem;
      border: 1px solid var(--mc-border);
      border-radius: var(--mc-radius-sm);
      background: #fff;
      color: var(--mc-text);
      font: inherit;
    }

    .btn-danger {
      background: #dc2626;
    }

    .btn-danger:hover {
      background: #b91c1c;
    }

    .admin-empty-action {
      padding: 0.8rem 0;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      padding: 0.7rem 1.25rem;
      background: linear-gradient(135deg, var(--mc-primary), var(--mc-primary-dark));
      color: #fff;
      border: none;
      border-radius: 10px;
      font-family: inherit;
      font-size: 0.875rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      box-shadow: 0 2px 8px rgba(13, 148, 136, 0.25);
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
    }

    .btn:active { transform: translateY(0); }

    .btn-secondary {
      background: var(--mc-surface);
      color: var(--mc-text);
      border: 1px solid var(--mc-border);
      box-shadow: none;
    }

    .btn-secondary:hover {
      background: var(--mc-bg);
      border-color: #cbd5e1;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
    }

    /* Schedule form */
    .admin-schedule-form {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
    }

    .admin-schedule-form label {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--mc-text-muted);
    }

    .admin-schedule-form input {
      padding: 0.6rem 0.75rem;
      border: 1px solid var(--mc-border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.875rem;
      color: var(--mc-text);
      background: var(--mc-bg);
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .admin-schedule-form input:focus {
      outline: none;
      border-color: var(--mc-primary);
      box-shadow: 0 0 0 3px var(--mc-primary-light);
      background: #fff;
    }

    .admin-schedule-form button {
      grid-column: 1 / -1;
      margin-top: 0.25rem;
    }

    /* Section */
    .admin-section {
      background: var(--mc-surface);
      border: 1px solid var(--mc-border);
      border-radius: var(--mc-radius);
      padding: 1.75rem;
      box-shadow: var(--mc-shadow);
    }

    .queue-heading { margin-bottom: 1.25rem; }

    .queue-heading h2 {
      margin: 0.25rem 0 0.3rem;
      font-size: 1.35rem;
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .queue-heading p {
      margin: 0;
      font-size: 0.875rem;
      color: var(--mc-text-muted);
    }

    /* Tabs */
    .admin-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
      padding: 0.35rem;
      background: var(--mc-bg);
      border-radius: 12px;
      margin-bottom: 1.5rem;
    }

    .admin-tab {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.55rem 0.95rem;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--mc-text-muted);
      text-decoration: none;
      transition: all 0.15s ease;
      white-space: nowrap;
    }

    .admin-tab:hover {
      color: var(--mc-text);
      background: rgba(255, 255, 255, 0.7);
    }

    .admin-tab.active {
      background: var(--mc-surface);
      color: var(--mc-primary-dark);
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }

    .admin-tab strong {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 24px;
      height: 22px;
      padding: 0 0.4rem;
      background: var(--mc-border);
      color: var(--mc-text-muted);
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .admin-tab.active strong {
      background: var(--mc-primary-light);
      color: var(--mc-primary-dark);
    }

    /* Table */
    .admin-table-wrap {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .doctor-review-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
      padding: 1.25rem 1.35rem;
      border: 1px solid var(--mc-border);
      border-radius: var(--mc-radius-sm);
      background: var(--mc-surface);
      transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
    }

    .doctor-review-row:hover {
      border-color: #cbd5e1;
      box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06);
      transform: translateY(-1px);
    }

    .doctor-review-info { min-width: 0; flex: 1; }

    .doctor-review-info h2 {
      margin: 0 0 0.2rem;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--mc-text);
    }

    .doctor-review-info p {
      margin: 0 0 0.15rem;
      font-size: 0.85rem;
      color: var(--mc-text-muted);
      word-break: break-word;
    }

    .doctor-review-info small {
      display: inline-block;
      margin-top: 0.35rem;
      font-size: 0.75rem;
      color: #94a3b8;
    }

    .doctor-review-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
      flex-wrap: wrap;
    }

    .doctor-review-actions form { margin: 0; }

    .doctor-review-actions .btn {
      padding: 0.5rem 0.95rem;
      font-size: 0.8rem;
    }

    /* Status badges */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.35rem 0.7rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .status-badge::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .status-pending {
      background: var(--mc-warning-bg);
      color: #b45309;
    }

    .status-approved {
      background: var(--mc-success-bg);
      color: #047857;
    }

    .status-rejected {
      background: var(--mc-danger-bg);
      color: #b91c1c;
    }

    .empty-state {
      margin: 0;
      padding: 2.5rem 1rem;
      text-align: center;
      color: var(--mc-text-muted);
      font-size: 0.9rem;
      background: var(--mc-bg);
      border-radius: var(--mc-radius-sm);
      border: 1px dashed var(--mc-border);
    }

    /* Responsive */
    @media (max-width: 720px) {
      .admin-delete-controls { flex-direction: column; }
      .topbar { padding: 0 1rem; }
      .admin-shell { padding: 1.25rem 1rem 3rem; }
      .admin-hero { min-height: 180px; }
      .admin-hero-copy { padding: 1.5rem; }
      .admin-hero-copy h1 { font-size: 1.5rem; }
      .admin-section { padding: 1.25rem; }
      .admin-action-card { padding: 1.25rem; }
      .admin-schedule-form { grid-template-columns: 1fr; }
      .doctor-review-row {
        flex-direction: column;
        align-items: flex-start;
      }
      .doctor-review-actions { width: 100%; }
      .doctor-review-actions .btn { flex: 1; }
      .admin-metric strong { font-size: 1.75rem; }
    }
  </style>
</head>
<body class="admin-page">
  <header class="topbar">
    <a class="brand-mark" href="admin-dashboard.php"><span>MC</span> MedConnect</a>
    <nav>
      <a href="admin-reset.php">Reset credentials</a>
      <a href="admin-logout.php">Sign out</a>
    </nav>
  </header>

  <main class="admin-shell">
    <section class="admin-hero" aria-label="Administrator workspace image">
      <img src="https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1400&q=85" alt="Healthcare professional reviewing information on a tablet" />
      <div class="admin-hero-copy">
        <p class="eyebrow">Administrator workspace</p>
        <h1>Doctor verification</h1>
      </div>
    </section>

    <div class="page-heading">
      <div>
        <p class="eyebrow">Overview</p>
        <h2>Keep your care network organized</h2>
        <p>Review clinician applications, assign patients, and export your records from one workspace.</p>
      </div>
    </div>

    <div class="admin-alerts" aria-live="polite">
      <?php if (isset($_GET['updated'])): ?><p class="success-message">Doctor marked as <?= e($_GET['updated']) ?>.</p><?php endif; ?>
      <?php if (isset($_GET['exported'])): ?><p class="success-message">CSV files were saved in the exports folder.</p><?php endif; ?>
      <?php if (isset($_GET['bulk_appointments'])): ?><p class="success-message">Created <?= (int) $_GET['bulk_appointments'] ?> pending appointment(s) and assigned patients to approved doctors.</p><?php endif; ?>
      <?php if (isset($_GET['deleted_patient'])): ?><p class="success-message">Patient <?= e($_GET['deleted_patient']) ?> and all related records were deleted.</p><?php endif; ?>
      <?php if (isset($_GET['error']) && in_array($_GET['error'], ['invalid_schedule', 'missing_doctors_or_patients', 'bulk_schedule_failed'], true)): ?><p class="error-message">The bulk appointment operation could not be completed. Check that the date is in the future and that approved doctors and patients exist.</p><?php endif; ?>
      <?php if (isset($_GET['error']) && in_array($_GET['error'], ['invalid_patient', 'patient_not_found', 'patient_delete_failed'], true)): ?><p class="error-message">The patient could not be deleted. Confirm that the patient still exists and try again.</p><?php endif; ?>
    </div>

    <section class="admin-metrics" aria-label="Verification queue summary">
      <div class="admin-metric">
        <span>Pending review</span>
        <strong><?= (int) ($counts['pending'] ?? 0) ?></strong>
        <small>Needs attention</small>
      </div>
      <div class="admin-metric">
        <span>Accepted applications</span>
        <strong><?= (int) ($counts['approved'] ?? 0) ?></strong>
        <small>Active access</small>
      </div>
      <div class="admin-metric">
        <span>Rejected doctors</span>
        <strong><?= (int) ($counts['rejected'] ?? 0) ?></strong>
        <small>Not approved</small>
      </div>
      <div class="admin-metric">
        <span>Total patients</span>
        <strong><?= $patientCount ?></strong>
        <small>Registered accounts</small>
      </div>
    </section>

    <section class="admin-actions-grid" aria-label="Administrator actions">
      <article class="admin-action-card">
        <div class="admin-section-heading">
          <span class="admin-section-icon" aria-hidden="true">CSV</span>
          <div>
            <h2>Export database data</h2>
            <p>Save non-secret records for doctors, patients, appointments, health metrics, suggestions, and prescriptions.</p>
          </div>
        </div>
        <a class="btn" href="export-data.php">Save CSV files</a>
      </article>

      <article class="admin-action-card">
        <div class="admin-section-heading">
          <span class="admin-section-icon" aria-hidden="true">A</span>
          <div>
            <h2>Assign patients</h2>
            <p>Create missing pending appointments and distribute patients across approved doctors.</p>
          </div>
        </div>
        <form method="POST" class="admin-schedule-form">
          <input type="hidden" name="bulk_assign_appointments" value="1">
          <label>Start date
            <input type="date" name="appointment_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
          </label>
          <label>First time
            <input type="time" name="appointment_time" value="09:00" required>
          </label>
          <button class="btn" type="submit">Create appointments</button>
        </form>
      </article>
      <article class="admin-action-card admin-danger-card">
        <div class="admin-section-heading">
          <span class="admin-section-icon" aria-hidden="true">!</span>
          <div>
            <h2>Delete a patient</h2>
            <p>Permanently removes the patient and their appointments, health metrics, suggestions, and prescriptions.</p>
          </div>
        </div>
        <?php if ($patients): ?>
          <form method="POST" action="admin-delete-patient.php" class="admin-delete-form" onsubmit="return confirm('This permanently deletes the patient and all related medical records. Continue?');">
            <label for="patient_id">Select patient</label>
            <div class="admin-delete-controls">
              <select id="patient_id" name="patient_id" required>
                <option value="">Choose a patient</option>
                <?php foreach ($patients as $patient): ?>
                  <option value="<?= (int) $patient['id'] ?>"><?= e($patient['fullname']) ?> — <?= e($patient['email']) ?> (ID <?= (int) $patient['id'] ?>)</option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-danger" type="submit">Delete patient</button>
            </div>
          </form>
        <?php else: ?>
          <p class="admin-empty-action">There are no patients to delete.</p>
        <?php endif; ?>
      </article>
    </section>

    <section class="admin-section" aria-labelledby="doctor-queue-heading">
      <div class="admin-section-heading queue-heading">
        <div>
          <p class="eyebrow">Verification queue</p>
          <h2 id="doctor-queue-heading">Doctor applications</h2>
          <p>Approve clinicians before they access the doctor workspace.</p>
        </div>
      </div>

      <div class="admin-tabs" aria-label="Doctor application status filters">
        <?php foreach (['pending', 'approved', 'rejected', 'all'] as $tab): ?>
          <a class="admin-tab<?= $statusFilter === $tab ? ' active' : '' ?>" href="?status=<?= $tab ?>">
            <?= $tab === 'pending' ? 'Pending' : ($tab === 'approved' ? 'Accepted' : ($tab === 'rejected' ? 'Rejected' : 'All doctors')) ?>
            <strong><?= $tab === 'all' ? array_sum($counts) : (int) ($counts[$tab] ?? 0) ?></strong>
          </a>
        <?php endforeach; ?>
      </div>

      <section class="admin-table-wrap">
        <?php if (!$doctors): ?><p class="empty-state">No doctors found in this queue.</p><?php endif; ?>
        <?php foreach ($doctors as $doctor): ?>
          <article class="doctor-review-row">
            <div class="doctor-review-info">
              <h2><?= e($doctor['fullname']) ?></h2>
              <p><?= e($doctor['department']) ?> &middot; <?= e($doctor['email']) ?></p>
              <p><?= e($doctor['phone']) ?> &middot; Username: <?= e($doctor['username']) ?></p>
              <small>Registered <?= e($doctor['created_at']) ?></small>
            </div>
            <div class="doctor-review-actions">
              <span class="status-badge status-<?= e($doctor['verification_status']) ?>"><?= ucfirst(e($doctor['verification_status'])) ?></span>
              <?php if ($doctor['verification_status'] !== 'approved'): ?>
                <form method="POST" action="admin-action.php">
                  <input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>" />
                  <input type="hidden" name="status" value="approved" />
                  <button class="btn" type="submit">Approve</button>
                </form>
              <?php endif; ?>
              <?php if ($doctor['verification_status'] !== 'rejected'): ?>
                <form method="POST" action="admin-action.php">
                  <input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>" />
                  <input type="hidden" name="status" value="rejected" />
                  <button class="btn btn-secondary" type="submit">Reject</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    </section>
  </main>
</body>
</html>
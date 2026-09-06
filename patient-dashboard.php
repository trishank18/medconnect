<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: patient-login.html");
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    // Fetch patient profile
    $stmt = $conn->prepare("SELECT * FROM patients WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['patient_id']);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        session_destroy();
        header("Location: patient-login.html?error=patient_not_found");
        exit();
    }

    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $fullname = $_POST['fullname'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $age = $_POST['age'];

        $updateStmt = $conn->prepare("UPDATE patients SET fullname = :fullname, email = :email, phone = :phone, age = :age WHERE id = :id");
        $updateStmt->bindParam(':fullname', $fullname);
        $updateStmt->bindParam(':email', $email);
        $updateStmt->bindParam(':phone', $phone);
        $updateStmt->bindParam(':age', $age);
        $updateStmt->bindParam(':id', $_SESSION['patient_id']);
        $updateStmt->execute();

        // Refresh patient data
        $stmt->execute();
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if ($appointmentId) {
            $cancelStmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = :id AND patient_id = :patient_id AND status = 'pending'");
            $cancelStmt->execute([
                ':id' => $appointmentId,
                ':patient_id' => $_SESSION['patient_id']
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
        ORDER BY recorded_at DESC
        LIMIT 1
    ");
    $stmt->bindParam(':patient_id', $_SESSION['patient_id']);
    $stmt->execute();
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    $healthTone = static function ($value, $goodMin, $goodMax, $moderateMin, $moderateMax): string {
        if ($value === null || $value === '') {
            return 'metric-neutral';
        }

        $numericValue = (float) $value;
        if ($numericValue >= $goodMin && $numericValue <= $goodMax) {
            return 'metric-health-good';
        }
        if ($numericValue >= $moderateMin && $numericValue <= $moderateMax) {
            return 'metric-health-moderate';
        }
        return 'metric-health-bad';
    };

    $heartRateTone = $healthTone($metrics['heart_rate'] ?? null, 60, 100, 50, 120);
    $spo2Tone = $healthTone($metrics['spo2'] ?? null, 95, 100, 90, 94.99);
    $temperatureTone = $healthTone($metrics['temperature'] ?? null, 36.1, 37.2, 35, 38);
    if (isset($metrics['blood_pressure_sys'], $metrics['blood_pressure_dia'])) {
        $systolic = (float) $metrics['blood_pressure_sys'];
        $diastolic = (float) $metrics['blood_pressure_dia'];
        $pressureTone = ($systolic >= 90 && $systolic <= 120 && $diastolic >= 60 && $diastolic <= 80)
            ? 'metric-health-good'
            : (($systolic >= 80 && $systolic <= 139 && $diastolic >= 50 && $diastolic <= 89)
                ? 'metric-health-moderate'
                : 'metric-health-bad');
    } else {
        $pressureTone = 'metric-neutral';
    }

    // Fetch the complete history so the patient can review every recorded reading.
    $history_stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        ORDER BY recorded_at DESC
    ");
    $history_stmt->bindParam(':patient_id', $_SESSION['patient_id']);
    $history_stmt->execute();
    $metrics_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $appt_stmt->bindParam(':patient_id', $_SESSION['patient_id']);
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
    $presc_stmt->bindParam(':patient_id', $_SESSION['patient_id']);
    $presc_stmt->execute();
    $prescriptions = $presc_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Patient Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/business.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">
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
                    <li><a class="dropdown-item text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['appointment_cancelled'])): ?>
        <div class="alert alert-success" role="alert">Appointment removed successfully.</div>
    <?php endif; ?>

    <!-- Health Metrics Display (unchanged) -->
    <h4 class="mb-3">🩺 Latest Health Metrics</h4>
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-danger health-metric-card metric-heart <?= $heartRateTone ?>">
                <div class="card-body">
                    <h5 class="card-title">Heart Rate</h5>
                    <p class="card-text fs-4">❤️ <?= $metrics['heart_rate'] ?? 'N/A' ?> BPM</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-info health-metric-card metric-oxygen <?= $spo2Tone ?>">
                <div class="card-body">
                    <h5 class="card-title">SpO₂ Level</h5>
                    <p class="card-text fs-4">🫁 <?= $metrics['spo2'] ?? 'N/A' ?> %</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-warning health-metric-card metric-temperature <?= $temperatureTone ?>">
                <div class="card-body">
                    <h5 class="card-title">Temperature</h5>
                    <p class="card-text fs-4">🌡️ <?= $metrics['temperature'] ?? 'N/A' ?> °C</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-secondary health-metric-card metric-pressure <?= $pressureTone ?>">
                <div class="card-body">
                    <h5 class="card-title">Blood Pressure</h5>
                    <p class="card-text fs-4">💓 
                        <?= isset($metrics['blood_pressure_sys'], $metrics['blood_pressure_dia']) 
                            ? "{$metrics['blood_pressure_sys']}/{$metrics['blood_pressure_dia']} mmHg"
                            : 'N/A' ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-8 mb-3">
            <div class="card border-primary health-metric-card metric-updated">
                <div class="card-body">
                    <h5 class="card-title">Last Updated</h5>
                    <p class="card-text">🕒 <?= $metrics['recorded_at'] ?? 'No data available' ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Rest of the content remains unchanged -->
    <div class="mb-4">
        <a href="add-health.php" class="btn btn-outline-success me-2">
            <i class="bi bi-plus-circle"></i> Add Health Data
        </a>
    </div>

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

    <!-- Appointment Booking Form -->
    <hr class="my-5">
    <h4 id="book-appointment"><i class="bi bi-calendar2-plus"></i> Book an Appointment</h4>
    <form action="book_appointment.php" method="POST" class="row g-3">
        <div class="col-md-4">
            <label for="department_select" class="form-label">1. Select Department</label>
            <select class="form-select" id="department_select" required>
                <option value="">Choose a department</option>
                <?php foreach (array_keys($doctors_by_department) as $department): ?>
                    <option value="<?= htmlspecialchars($department, ENT_QUOTES) ?>"><?= htmlspecialchars($department) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
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
            <input type="date" class="form-control" name="appointment_date" required>
        </div>

        <div class="col-md-3">
            <label for="appointment_time" class="form-label">Time</label>
            <input type="time" class="form-control" name="appointment_time" required>
        </div>

        <div class="col-12">
            <label for="notes" class="form-label">Notes (Optional)</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
        </div>

        <div class="col-12">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-calendar-plus"></i> Book Appointment
            </button>
        </div>
    </form>

    <!-- Appointments Table -->
    <hr class="my-5">
    <h4 id="appointments"><i class="bi bi-calendar-check"></i> Your Appointments</h4>
    <div class="table-responsive">
        <table class="table table-striped">
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

    <!-- Prescriptions Table -->
    <hr class="my-5">
    <h4 id="prescriptions"><i class="bi bi-prescription2"></i> Prescriptions</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
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
</div>

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
            <div class="modal-header">
                <h5 class="modal-title">Update Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="update_profile" value="1" />
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($patient['fullname']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($patient['email']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($patient['phone']) ?>" required>
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
    <script src="js/design-switch.js"></script>
</body>
</html>
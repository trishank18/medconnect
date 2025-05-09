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

    // Fetch health metrics history (last 10 records)
    $history_stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        ORDER BY recorded_at DESC
        LIMIT 10
    ");
    $history_stmt->bindParam(':patient_id', $_SESSION['patient_id']);
    $history_stmt->execute();
    $metrics_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare data for Chart.js
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
    $doctors = $conn->query("SELECT id, fullname, department FROM doctors")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch patient appointments
    $appt_stmt = $conn->prepare("
        SELECT a.*, d.fullname AS doctor_name 
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
        SELECT pp.*, d.fullname AS doctor_name 
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">
<div class="container py-5">
    <!-- Updated Header with Profile Dropdown -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Welcome, <?= htmlspecialchars($patient['fullname']) ?></h2>
        <div>
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                <i class="bi bi-pencil-square"></i> Edit Profile
            </button>
            
            <!-- Profile Dropdown -->
            <div class="dropdown d-inline-block">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars(explode(' ', $patient['fullname'])[0]) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#viewProfileModal">
                        <i class="bi bi-eye"></i> View Profile
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Health Metrics Display (unchanged) -->
    <h4 class="mb-3">🩺 Latest Health Metrics</h4>
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <h5 class="card-title">Heart Rate</h5>
                    <p class="card-text fs-4">❤️ <?= $metrics['heart_rate'] ?? 'N/A' ?> BPM</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5 class="card-title">SpO₂ Level</h5>
                    <p class="card-text fs-4">🫁 <?= $metrics['spo2'] ?? 'N/A' ?> %</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5 class="card-title">Temperature</h5>
                    <p class="card-text fs-4">🌡️ <?= $metrics['temperature'] ?? 'N/A' ?> °C</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-secondary">
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
            <div class="card border-primary">
                <div class="card-body">
                    <h5 class="card-title">Last Updated</h5>
                    <p class="card-text">🕒 <?= $metrics['recorded_at'] ?? 'No data available' ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Rest of the content remains unchanged -->
    <div class="mb-4">
        <a href="add-health.html" class="btn btn-outline-success me-2">
            <i class="bi bi-plus-circle"></i> Add Health Data
        </a>
    </div>

    <!-- Health Metrics History Graph -->
    <div class="card mb-5">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📈 Health Metrics History (Last 10 Records)</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-12">
                    <canvas id="healthMetricsChart" height="300"></canvas>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <canvas id="heartRateChart" height="200"></canvas>
                </div>
                <div class="col-md-6">
                    <canvas id="spo2Chart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointment Booking Form -->
    <hr class="my-5">
    <h4>📅 Book an Appointment</h4>
    <form action="book_appointment.php" method="POST" class="row g-3">
        <div class="col-md-6">
            <label for="doctor_id" class="form-label">Select Doctor</label>
            <select class="form-select" id="doctor_id" name="doctor_id" required>
                <option value="">Choose a doctor</option>
                <?php foreach ($doctors as $doc): ?>
                    <option value="<?= $doc['id'] ?>"><?= $doc['fullname'] ?> (<?= $doc['department'] ?>)</option>
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
    <h4>📝 Your Appointments</h4>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($appointments): foreach ($appointments as $appt): ?>
                    <tr>
                        <td><?= htmlspecialchars($appt['doctor_name']) ?></td>
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
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center">No appointments found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Prescriptions Table -->
    <hr class="my-5">
    <h4>💊 Prescriptions</h4>
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
                </tr>
            </thead>
            <tbody>
                <?php if ($prescriptions): foreach ($prescriptions as $presc): ?>
                    <tr>
                        <td><?= htmlspecialchars($presc['doctor_name']) ?></td>
                        <td><?= htmlspecialchars($presc['medication'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($presc['dosage'] ?? 'N/A') ?></td>
                        <td><?= nl2br(htmlspecialchars($presc['instructions'] ?? 'N/A')) ?></td>
                        <td><?= nl2br(htmlspecialchars($presc['prescription'])) ?></td>
                        <td><?= date("d M Y, h:i A", strtotime($presc['created_at'])) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center">No prescriptions found.</td></tr>
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
// Health Metrics Chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('healthMetricsChart').getContext('2d');
    const healthChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Heart Rate (BPM)',
                    data: <?= json_encode($heart_rate_data) ?>,
                    borderColor: 'rgb(220, 53, 69)',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y'
                },
                {
                    label: 'SpO₂ (%)',
                    data: <?= json_encode($spo2_data) ?>,
                    borderColor: 'rgb(13, 110, 253)',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y1'
                },
                {
                    label: 'Temperature (°C)',
                    data: <?= json_encode($temperature_data) ?>,
                    borderColor: 'rgb(255, 193, 7)',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Heart Rate (BPM)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false,
                    },
                    title: {
                        display: true,
                        text: 'SpO₂ (%)'
                    },
                    min: 90,
                    max: 100
                },
                y2: {
                    type: 'linear',
                    display: false,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false,
                    },
                    title: {
                        display: true,
                        text: 'Temperature (°C)'
                    }
                }
            }
        }
    });

    // Heart Rate Chart
    const hrCtx = document.getElementById('heartRateChart').getContext('2d');
    const hrChart = new Chart(hrCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                label: 'Heart Rate (BPM)',
                data: <?= json_encode($heart_rate_data) ?>,
                borderColor: 'rgb(220, 53, 69)',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Heart Rate Trend'
                }
            }
        }
    });

    // SpO2 Chart
    const spo2Ctx = document.getElementById('spo2Chart').getContext('2d');
    const spo2Chart = new Chart(spo2Ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                label: 'SpO₂ (%)',
                data: <?= json_encode($spo2_data) ?>,
                borderColor: 'rgb(13, 110, 253)',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Oxygen Saturation Trend'
                }
            },
            scales: {
                y: {
                    min: 90,
                    max: 100
                }
            }
        }
    });
});
</script>
</body>
</html>
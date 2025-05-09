<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: doctor-login.html");
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    // Fetch doctor info
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['doctor_id']);
    $stmt->execute();
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        session_destroy();
        header("Location: doctor-login.html?error=not_found");
        exit();
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_profile'])) {
            $fullname = $_POST['fullname'];
            $email = $_POST['email'];
            $phone = $_POST['phone'];
            $department = $_POST['department'];
            
            $updateStmt = $conn->prepare("UPDATE doctors SET fullname = :fullname, email = :email, phone = :phone, department = :department WHERE id = :id");
            $updateStmt->bindParam(':fullname', $fullname);
            $updateStmt->bindParam(':email', $email);
            $updateStmt->bindParam(':phone', $phone);
            $updateStmt->bindParam(':department', $department);
            $updateStmt->bindParam(':id', $_SESSION['doctor_id']);
            $updateStmt->execute();
            
            // Refresh doctor data
            $stmt->execute();
            $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (isset($_POST['add_prescription'])) {
            $patient_id = $_POST['patient_id'];
            $appointment_id = $_POST['appointment_id'];
            $prescription = $_POST['prescription'];
            $medication = $_POST['medication'] ?? '';
            $dosage = $_POST['dosage'] ?? '';
            $instructions = $_POST['instructions'] ?? '';
            
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
        } elseif (isset($_POST['update_status'])) {
            $appointment_id = $_POST['appointment_id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            $updateStmt = $conn->prepare("UPDATE appointments SET status = :status, notes = :notes WHERE id = :id AND doctor_id = :doctor_id");
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':notes', $notes);
            $updateStmt->bindParam(':id', $appointment_id);
            $updateStmt->bindParam(':doctor_id', $_SESSION['doctor_id']);
            $updateStmt->execute();
        }
    }

    // Count of patients
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT p.id) AS total FROM patients p INNER JOIN appointments a ON a.patient_id = p.id WHERE a.doctor_id = :doc_id");
    $stmt->bindParam(':doc_id', $_SESSION['doctor_id']);
    $stmt->execute();
    $patientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch patients with latest health metrics
    $stmt = $conn->prepare("
        SELECT p.id, p.fullname, p.age, p.email, p.phone,
               hm.heart_rate, hm.spo2, hm.temperature,
               hm.blood_pressure_sys, hm.blood_pressure_dia, hm.recorded_at
        FROM patients p
        INNER JOIN appointments a ON a.patient_id = p.id
        LEFT JOIN (
            SELECT hm.*
            FROM health_metrics hm
            INNER JOIN (
                SELECT patient_id, MAX(recorded_at) as max_time
                FROM health_metrics
                GROUP BY patient_id
            ) latest ON hm.patient_id = latest.patient_id AND hm.recorded_at = latest.max_time
        ) hm ON p.id = hm.patient_id
        WHERE a.doctor_id = :doc_id
        GROUP BY p.id
        ORDER BY p.fullname
    ");
    $stmt->bindParam(':doc_id', $_SESSION['doctor_id']);
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch health history for each patient
    foreach ($patients as &$patient) {
        $stmt = $conn->prepare("
            SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
            FROM health_metrics
            WHERE patient_id = :patient_id
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL 10 DAY)
            ORDER BY recorded_at DESC
        ");
        $stmt->bindParam(':patient_id', $patient['id']);
        $stmt->execute();
        $patient['health_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($patient); // Break the reference

    // Fetch all appointments with prescriptions
    $stmt = $conn->prepare("
        SELECT a.*, p.fullname AS patient_name, 
               (SELECT GROUP_CONCAT(pp.prescription SEPARATOR '|||') 
                FROM patient_prescriptions pp 
                WHERE pp.appointment_id = a.id
                ORDER BY pp.created_at DESC) AS prescriptions
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.id
        WHERE a.doctor_id = :doc_id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bindParam(':doc_id', $_SESSION['doctor_id']);
    $stmt->execute();
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
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
    .badge-accepted { background-color: #17a2b8; }
    .prescription-card { border-left: 4px solid #0d6efd; margin-bottom: 10px; }
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
</head>
<body class="bg-light">
  <div class="container py-5">
    <!-- Updated Header with Profile Dropdown -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2>Welcome, Dr. <?= htmlspecialchars($doctor['fullname']) ?> <small class="text-muted">(<?= htmlspecialchars($doctor['department']) ?>)</small></h2>
      <div>
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
          <i class="bi bi-pencil-square"></i> Edit Profile
        </button>
        
        <div class="dropdown d-inline-block">
          <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="profile-icon me-2">
              <?= strtoupper(substr($doctor['fullname'], 0, 1)) ?>
            </div>
            <?= htmlspecialchars(explode(' ', $doctor['fullname'])[0]) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
            <li><h6 class="dropdown-header">Dr. <?= htmlspecialchars($doctor['fullname']) ?></h6></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#viewProfileModal">
              <i class="bi bi-person-lines-fill"></i> View Profile
            </a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php">
              <i class="bi bi-box-arrow-right"></i> Logout
            </a></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
      <div class="col-md-4">
        <div class="card text-white bg-primary health-metric-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title"><?= $patientCount ?></h5>
                <p class="card-text">Patients Assigned</p>
              </div>
              <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card text-white bg-success health-metric-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title"><?= count(array_filter($appointments, fn($a) => $a['status'] === 'completed')) ?></h5>
                <p class="card-text">Completed Appointments</p>
              </div>
              <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card text-white bg-warning health-metric-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title"><?= count(array_filter($appointments, fn($a) => $a['status'] === 'pending')) ?></h5>
                <p class="card-text">Pending Appointments</p>
              </div>
              <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

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
                  <th>Date/Time</th>
                  <th>Status</th>
                  <th>Notes</th>
                  <th>Prescriptions</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($appointments as $appt): ?>
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
                      <?= date("M j, Y", strtotime($appt['appointment_date'])) ?>
                      <div class="text-muted small"><?= date("H:i", strtotime($appt['appointment_time'])) ?></div>
                    </td>
                    <td>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                        <select name="status" class="form-select status-dropdown" onchange="this.form.submit()">
                          <option value="pending" <?= $appt['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                          <option value="accepted" <?= $appt['status'] === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                          <option value="completed" <?= $appt['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                          <option value="cancelled" <?= $appt['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                      </form>
                    </td>
                    <td>
                      <form method="POST">
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
                      <?php if (!empty($appt['prescriptions'])): ?>
                        <div class="prescription-list">
                          <?php foreach (explode('|||', $appt['prescriptions']) as $prescription): ?>
                            <div class="prescription-card p-2 mb-2 bg-light small">
                              <?= nl2br(htmlspecialchars($prescription)) ?>
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
              <?= strtoupper(substr($doctor['fullname'], 0, 1)) ?>
            </div>
            <h3 class="mt-3">Dr. <?= htmlspecialchars($doctor['fullname']) ?></h3>
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
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Update Profile</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="update_profile" value="1" />
          <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($doctor['fullname']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($doctor['email']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($doctor['phone']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Department</label>
            <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($doctor['department']) ?>" required>
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
              <input type="text" name="medication" class="form-control" placeholder="Enter medication name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Dosage</label>
              <input type="text" name="dosage" class="form-control" placeholder="e.g., 500mg, 1 tablet">
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
      const patients = <?= json_encode($patients) ?>;
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
      const heartRates = healthHistory.map(entry => entry.heart_rate || null);
      const spo2s = healthHistory.map(entry => entry.spo2 || null);
      const temps = healthHistory.map(entry => entry.temperature || null);
      const bpSys = healthHistory.map(entry => entry.blood_pressure_sys || null);
      const bpDia = healthHistory.map(entry => entry.blood_pressure_dia || null);
      
      // Update table
      const tableBody = document.getElementById('healthMetricsTableBody');
      tableBody.innerHTML = '';
      healthHistory.forEach(entry => {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${new Date(entry.recorded_at).toLocaleString()}</td>
          <td>${entry.heart_rate || 'N/A'}</td>
          <td>${entry.spo2 || 'N/A'}</td>
          <td>${entry.temperature || 'N/A'}</td>
          <td>${entry.blood_pressure_sys && entry.blood_pressure_dia 
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
  </script>
</body>
</html>
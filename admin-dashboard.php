<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin-login.html');
    exit();
}

$statusFilter = $_GET['status'] ?? 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
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
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="MedConnect administrator dashboard" />
  <title>Admin Dashboard - MedConnect</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body class="admin-page">
  <header class="topbar">
    <a class="brand-mark" href="admin-dashboard.php"><span>MC</span> MedConnect</a>
    <nav><a href="admin-logout.php">Sign out</a></nav>
  </header>
  <main class="admin-shell">
    <section class="admin-hero" aria-label="Administrator workspace image">
      <img src="https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1400&q=85" alt="Healthcare professional reviewing information on a tablet" />
      <div class="admin-hero-copy"><p class="eyebrow">Administrator workspace</p><h1>Doctor verification</h1></div>
    </section>
    <div class="page-heading"><div><p>Review clinician applications before they can access the doctor workspace.</p></div></div>
    <?php if (isset($_GET['updated'])): ?><p class="success-message">Doctor marked as <?= e($_GET['updated']) ?>.</p><?php endif; ?>
    <section class="admin-metrics" aria-label="Verification queue summary">
      <div class="admin-metric"><span>Pending review</span><strong><?= (int) ($counts['pending'] ?? 0) ?></strong><small>Needs attention</small></div>
      <div class="admin-metric"><span>Accepted applications</span><strong><?= (int) ($counts['approved'] ?? 0) ?></strong><small>Active access</small></div>
      <div class="admin-metric"><span>Rejected doctors</span><strong><?= (int) ($counts['rejected'] ?? 0) ?></strong><small>Not approved</small></div>
      <div class="admin-metric"><span>Total patients</span><strong><?= $patientCount ?></strong><small>Registered accounts</small></div>
    </section>
    <div class="admin-tabs" aria-label="Doctor application status filters">
      <?php foreach (['pending', 'approved', 'rejected', 'all'] as $tab): ?>
        <a class="admin-tab<?= $statusFilter === $tab ? ' active' : '' ?>" href="?status=<?= $tab ?>">
          <?= $tab === 'pending' ? 'Pending applications' : ($tab === 'approved' ? 'Accepted applications' : ($tab === 'rejected' ? 'Rejected applications' : 'All doctors')) ?> <strong><?= $tab === 'all' ? array_sum($counts) : (int) ($counts[$tab] ?? 0) ?></strong>
        </a>
      <?php endforeach; ?>
    </div>
    <section class="admin-table-wrap">
      <?php if (!$doctors): ?><p class="empty-state">No doctors found in this queue.</p><?php endif; ?>
      <?php foreach ($doctors as $doctor): ?>
        <article class="doctor-review-row">
          <div class="doctor-review-info"><h2><?= e($doctor['fullname']) ?></h2><p><?= e($doctor['department']) ?> &middot; <?= e($doctor['email']) ?></p><p><?= e($doctor['phone']) ?> &middot; Username: <?= e($doctor['username']) ?></p><small>Registered <?= e($doctor['created_at']) ?></small></div>
          <div class="doctor-review-actions"><span class="status-badge status-<?= e($doctor['verification_status']) ?>"><?= ucfirst(e($doctor['verification_status'])) ?></span>
            <?php if ($doctor['verification_status'] !== 'approved'): ?><form method="POST" action="admin-action.php"><input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>" /><input type="hidden" name="status" value="approved" /><button class="btn" type="submit">Approve</button></form><?php endif; ?>
            <?php if ($doctor['verification_status'] !== 'rejected'): ?><form method="POST" action="admin-action.php"><input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>" /><input type="hidden" name="status" value="rejected" /><button class="btn btn-secondary" type="submit">Reject</button></form><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>
<?php
require_once __DIR__ . '/includes/session.php';
if (!medconnect_role_authenticated('patient')) {
    header('Location: patient-login.html');
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    $patientStmt = $conn->prepare('SELECT fullname FROM patients WHERE id = :patient_id');
    $patientStmt->execute([':patient_id' => $_SESSION['auth']['patient']['id']]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        medconnect_clear_role_auth('patient');
        header('Location: patient-login.html?error=patient_not_found');
        exit();
    }

    $historyStmt = $conn->prepare(
        'SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
         FROM health_metrics
         WHERE patient_id = :patient_id
         ORDER BY recorded_at DESC, id DESC
         LIMIT 10'
    );
    $historyStmt->execute([':patient_id' => $_SESSION['auth']['patient']['id']]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('MedConnect patient analytics query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Health analytics are temporarily unavailable. Please try again later.');
}

$chartRows = array_reverse($history);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Analytics - MedConnect</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .prediction-hero { background: linear-gradient(135deg, #123044, #087f8c); }
        .score { font-size: 3.5rem; font-weight: 700; }
        .table td, .table th { white-space: nowrap; }
        .risk-indicator { align-items: center; display: inline-flex; gap: 10px; }
        .pattern-light { background: #43d6a0; border: 4px solid rgba(255,255,255,.45); border-radius: 50%; box-shadow: 0 0 0 5px rgba(67,214,160,.18); display: inline-block; height: 22px; width: 22px; }
        .pattern-change .pattern-light { background: #f1c84a; box-shadow: 0 0 0 5px rgba(241,200,74,.18); }
        .risk-label { font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .ai-prescription-icon { align-items: center; background: #e2efeb; border-radius: 50%; color: #087f8c; display: inline-flex; font-size: 1.2rem; height: 42px; justify-content: center; width: 42px; }
        .ai-guidance { border-left: 4px solid #168f83; }
    </style>
    <link rel="stylesheet" href="css/business.css">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/dashboard-modern.css?v=20261025-12">
    <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
</head>
<body class="bg-light modern-dashboard patient-modern-dashboard patient-prediction-page" data-user-name="<?= htmlspecialchars($patient['fullname'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="Patient">
<div class="container py-4 py-md-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <p class="text-muted mb-1"><i class="bi bi-robot"></i> AI health analytics</p>
            <h1 class="h2 mb-0">Welcome, <?= htmlspecialchars($patient['fullname']) ?></h1>
        </div>
        <div class="d-flex gap-2">
            <a href="add-health.php" class="btn btn-success"><i class="bi bi-plus-circle"></i> Add reading</a>
            <a href="patient-dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Main dashboard</a>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        This experimental pattern screen compares recorded measurements. It is not a validated risk score, diagnosis, emergency alert, or replacement for a clinician.
    </div>

    <section class="card border-0 shadow-sm mb-4 prediction-hero text-white">
        <div class="card-body p-4 p-md-5">
            <div class="row align-items-center g-4">
                <div class="col-md-7">
                    <p class="text-white-50 mb-2">Latest pattern analysis</p>
                    <div class="risk-indicator mb-2" id="riskIndicator"><span class="pattern-light" id="riskLight"></span><span class="risk-label" id="riskLabel">Checking pattern</span></div>
                    <h2 class="h3" id="predictionTitle">Checking recent readings...</h2>
                    <p class="mb-0" id="predictionMessage">The model is analyzing your personal pattern.</p>
                </div>
                <div class="col-md-5 text-md-end">
                    <p class="mb-0 text-white-50">Model output is not a measure of health or clinical risk.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <h2 class="h6 text-muted">AI status</h2>
                    <p class="h4 mb-0" id="predictionStatus">Loading</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <h2 class="h6 text-muted">Readings analyzed</h2>
                    <p class="h4 mb-0" id="predictionReadings">--</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <h2 class="h6 text-muted">Analysis method</h2>
                    <p class="h4 mb-0">Isolation Forest</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 ai-guidance mb-4">
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-3">
                <span class="ai-prescription-icon"><i class="bi bi-prescription2"></i></span>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1">General next steps</h2>
                    <p class="text-muted small mb-3">Use recorded values as context when speaking with a qualified clinician. This screen does not recommend treatment.</p>
                    <ul class="mb-0" id="aiGuidance"><li>Waiting for the latest pattern analysis...</li></ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Recent pattern</h2></div>
        <div class="card-body"><canvas id="predictionChart" height="110"></canvas></div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Recent readings</h2></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Recorded</th><th>Heart rate</th><th>SpO2</th><th>Temperature</th><th>Blood pressure</th></tr></thead>
                <tbody>
                <?php if ($history): foreach ($history as $reading): ?>
                    <tr>
                        <td><?= htmlspecialchars($reading['recorded_at']) ?></td>
                        <td><?= htmlspecialchars((string) ($reading['heart_rate'] ?? 'N/A')) ?> BPM</td>
                        <td><?= htmlspecialchars((string) ($reading['spo2'] ?? 'N/A')) ?>%</td>
                        <td><?= htmlspecialchars((string) ($reading['temperature'] ?? 'N/A')) ?> C</td>
                        <td><?= htmlspecialchars((string) ($reading['blood_pressure_sys'] ?? 'N/A')) ?>/<?= htmlspecialchars((string) ($reading['blood_pressure_dia'] ?? 'N/A')) ?> mmHg</td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted">No readings recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
const labels = <?= json_encode(array_map(static fn($row) => date('M j, H:i', strtotime($row['recorded_at'])), $chartRows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartRows = <?= json_encode($chartRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;


const predictionCanvas = document.getElementById('predictionChart');
if (window.Chart && predictionCanvas) {
    new Chart(predictionCanvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Heart rate', data: chartRows.map(row => row.heart_rate), borderColor: '#43d6a0', tension: 0.25 },
                { label: 'SpO₂', data: chartRows.map(row => row.spo2), borderColor: '#5b8fe0', tension: 0.25 },
                { label: 'Temperature', data: chartRows.map(row => row.temperature), borderColor: '#ff8a35', tension: 0.25 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { usePointStyle: true, boxWidth: 8 } } },
            scales: { x: { grid: { color: 'rgba(119,123,131,.12)' } }, y: { grid: { color: 'rgba(119,123,131,.12)' } } }
        }
    });
} else if (predictionCanvas) {
    predictionCanvas.insertAdjacentHTML('beforebegin', '<p class="prediction-chart-empty">The chart library is unavailable. Your recorded readings are still shown below.</p>');
    predictionCanvas.hidden = true;
}

const predictionController = new AbortController();
const predictionTimeout = window.setTimeout(() => predictionController.abort(), 12000);
fetch('predict_health.php', { signal: predictionController.signal, headers: { Accept: 'application/json' } })
    .then(response => response.json().then(result => ({ response, result })))
    .then(({ response, result }) => {
        if (!response.ok || !result.success) throw new Error(result.error || result.message || 'Prediction unavailable');
        const labels = {
            normal: ['Within recent pattern', 'No marked variation'],
            monitor: ['Some variation from recent pattern', 'Variation'],
            attention: ['Marked difference from recent pattern', 'Variation'],
            insufficient_data: ['Collecting more readings', 'Insufficient data'],
            unavailable: ['Prediction unavailable', 'Unavailable']
        };
        const [title, status] = labels[result.status] || ['Prediction unavailable', 'Unavailable'];
        const isDifferent = result.status === 'attention' || result.status === 'monitor';
        document.body.classList.toggle('pattern-change', isDifferent);
        document.getElementById('riskLabel').textContent = isDifferent ? 'Pattern variation' : status;
        document.getElementById('predictionTitle').textContent = title;
        document.getElementById('predictionStatus').textContent = status;
        document.getElementById('predictionMessage').textContent = result.message;
        document.getElementById('predictionReadings').textContent = result.readings_used ?? '--';
        const guidance = ['Review the underlying recorded readings and timestamps.', 'Discuss health concerns or persistent changes with a qualified clinician.'];
        document.getElementById('aiGuidance').innerHTML = guidance.map(item => `<li>${item}</li>`).join('');
    })
    .catch(error => {
        document.getElementById('predictionTitle').textContent = 'Prediction unavailable';
        document.getElementById('predictionStatus').textContent = 'Unavailable';
        document.getElementById('riskLabel').textContent = 'Unavailable';
        document.getElementById('predictionMessage').textContent = error.name === 'AbortError'
            ? 'The prediction service did not respond in time. Try again later.'
            : (error.message || 'The prediction service could not complete this analysis.');
        document.getElementById('aiGuidance').textContent = 'No pattern summary is available until the prediction service responds.';
    })
    .finally(() => window.clearTimeout(predictionTimeout));
</script>
    <script src="js/design-switch.js?v=2"></script>
</body>
</html>

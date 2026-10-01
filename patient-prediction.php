<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header('Location: patient-login.html');
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    $patientStmt = $conn->prepare('SELECT fullname FROM patients WHERE id = :patient_id');
    $patientStmt->execute([':patient_id' => $_SESSION['patient_id']]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        session_destroy();
        header('Location: patient-login.html?error=patient_not_found');
        exit();
    }

    $historyStmt = $conn->prepare(
        'SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
         FROM health_metrics
         WHERE patient_id = :patient_id
         ORDER BY recorded_at DESC
         LIMIT 10'
    );
    $historyStmt->execute([':patient_id' => $_SESSION['patient_id']]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    die('Database error: ' . htmlspecialchars($exception->getMessage()));
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
        .risk-light { background: #28a745; border: 4px solid rgba(255,255,255,.45); border-radius: 50%; box-shadow: 0 0 0 5px rgba(40,167,69,.18); display: inline-block; height: 22px; width: 22px; }
        .risk-high .risk-light { background: #dc3545; box-shadow: 0 0 0 5px rgba(220,53,69,.18); }
        .risk-high .risk-light.is-blinking { animation: riskPulse .8s infinite; }
        .risk-high .prediction-hero { background: linear-gradient(135deg, #7b1e2b, #dc3545); }
        .risk-good .prediction-hero { background: linear-gradient(135deg, #116149, #168f83); }
        .risk-label { font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .ai-prescription-icon { align-items: center; background: #e2efeb; border-radius: 50%; color: #087f8c; display: inline-flex; font-size: 1.2rem; height: 42px; justify-content: center; width: 42px; }
        .ai-guidance { border-left: 4px solid #168f83; }
        .sound-control { color: rgba(255,255,255,.85); font-size: .78rem; }
        @keyframes riskPulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .3; transform: scale(.78); } }
        @media (prefers-reduced-motion: reduce) { .risk-high .risk-light.is-blinking { animation: none; } }
    </style>
    <link rel="stylesheet" href="css/business.css">
</head>
<body class="bg-light">
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
        AI Analytics screens changes in your recent readings. It is not a diagnosis, emergency alert, or replacement for a clinician.
    </div>

    <section class="card border-0 shadow-sm mb-4 prediction-hero text-white">
        <div class="card-body p-4 p-md-5">
            <div class="row align-items-center g-4">
                <div class="col-md-7">
                    <p class="text-white-50 mb-2">Latest AI analysis</p>
                    <div class="risk-indicator mb-2" id="riskIndicator"><span class="risk-light" id="riskLight"></span><span class="risk-label" id="riskLabel">Checking risk</span></div>
                    <h2 class="h3" id="predictionTitle">Checking recent readings...</h2>
                    <p class="mb-0" id="predictionMessage">The model is analyzing your personal pattern.</p>
                    <button type="button" class="btn btn-sm btn-outline-light mt-3 sound-control" id="soundControl"><i class="bi bi-volume-up"></i> Alert sound on</button>
                </div>
                <div class="col-md-5 text-md-end">
                    <div class="score" id="predictionScore">--</div>
                    <div class="text-white-50">anomaly score / 100</div>
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
                    <h2 class="h5 mb-1">AI care guidance</h2>
                    <p class="text-muted small mb-3">Personalized next-step suggestions based on your latest pattern. This is not a prescription; confirm any treatment with a qualified clinician.</p>
                    <ul class="mb-0" id="aiGuidance"><li>Waiting for the latest analysis...</li></ul>
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
const labels = <?= json_encode(array_map(static fn($row) => date('M j, H:i', strtotime($row['recorded_at'])), $chartRows)) ?>;
const chartRows = <?= json_encode($chartRows) ?>;
let alertSoundEnabled = true;
let alertSoundContext;

function playRiskAlert() {
    if (!alertSoundEnabled) return;
    try {
        alertSoundContext = alertSoundContext || new (window.AudioContext || window.webkitAudioContext)();
        alertSoundContext.resume();
        const pattern = [true, true, true, false, false, false, true, true, true];
        let offset = 0;
        pattern.forEach(function (isShort) {
            const duration = isShort ? .12 : .35;
            const oscillator = alertSoundContext.createOscillator();
            const gain = alertSoundContext.createGain();
            const start = alertSoundContext.currentTime + offset;
            oscillator.frequency.value = 660;
            oscillator.type = 'sine';
            gain.gain.setValueAtTime(.0001, start);
            gain.gain.exponentialRampToValueAtTime(.12, start + .02);
            gain.gain.exponentialRampToValueAtTime(.0001, start + duration);
            oscillator.connect(gain).connect(alertSoundContext.destination);
            oscillator.start(start);
            oscillator.stop(start + duration + .01);
            offset += duration + .1;
        });
    } catch (error) {
        document.getElementById('soundControl').textContent = 'Enable alert sound';
    }
}

document.getElementById('soundControl').addEventListener('click', function () {
    alertSoundEnabled = !alertSoundEnabled;
    this.innerHTML = alertSoundEnabled ? '<i class="bi bi-volume-up"></i> Alert sound on' : '<i class="bi bi-volume-mute"></i> Alert sound off';
});

new Chart(document.getElementById('predictionChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            { label: 'Heart rate', data: chartRows.map(row => row.heart_rate), borderColor: '#dc3545', tension: 0.25 },
            { label: 'SpO2', data: chartRows.map(row => row.spo2), borderColor: '#0d6efd', tension: 0.25 },
            { label: 'Temperature', data: chartRows.map(row => row.temperature), borderColor: '#fd7e14', tension: 0.25 }
        ]
    },
    options: { responsive: true, interaction: { mode: 'index', intersect: false } }
});

fetch('predict_health.php')
    .then(response => response.json())
    .then(result => {
        if (!result.success) throw new Error(result.error || result.message || 'Prediction unavailable');
        const labels = {
            normal: ['Within recent pattern', 'Good'],
            monitor: ['Monitor recent changes', 'Low risk'],
            attention: ['Needs clinical attention', 'High risk'],
            insufficient_data: ['Collecting more readings', 'Insufficient data'],
            unavailable: ['Prediction unavailable', 'Unavailable']
        };
        const [title, status] = labels[result.status] || ['Prediction unavailable', 'Unavailable'];
        const isGood = result.status === 'normal' || result.status === 'monitor';
        const isHigh = result.status === 'attention';
        document.body.classList.toggle('risk-good', isGood);
        document.body.classList.toggle('risk-high', isHigh);
        document.getElementById('riskLight').classList.toggle('is-blinking', isHigh);
        document.getElementById('riskLabel').textContent = isGood ? 'Low risk' : isHigh ? 'High risk alert' : status;
        document.getElementById('predictionTitle').textContent = title;
        document.getElementById('predictionStatus').textContent = status;
        document.getElementById('predictionMessage').textContent = result.message;
        document.getElementById('predictionScore').textContent = result.anomaly_score ?? '--';
        document.getElementById('predictionReadings').textContent = result.readings_used ?? '--';
        const guidance = isGood
            ? ['Continue tracking your readings consistently.', 'Keep your normal care and medication routine.', 'Book a clinical review if you notice new symptoms.']
            : ['Arrange a clinical review if this pattern continues or symptoms appear.', 'Record another complete reading when you are rested and comfortable.', 'Do not start, stop, or change medication from this screen.'];
        document.getElementById('aiGuidance').innerHTML = guidance.map(item => `<li>${item}</li>`).join('');
        if (isHigh) playRiskAlert();
    })
    .catch(error => {
        document.getElementById('predictionTitle').textContent = 'Prediction unavailable';
        document.getElementById('predictionStatus').textContent = 'Unavailable';
        document.getElementById('predictionMessage').textContent = error.message;
    });
</script>
    <script src="js/design-switch.js"></script>
</body>
</html>

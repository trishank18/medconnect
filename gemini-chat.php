<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/assistant_session.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are supported.']);
    exit();
}
if (!isset($_SESSION['assistant_csrf']) || !hash_equals($_SESSION['assistant_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Please refresh the dashboard and try again.']);
    exit();
}
$now = time();
$recentRequests = array_values(array_filter($_SESSION['assistant_requests'] ?? [], static fn($timestamp) => is_int($timestamp) && $timestamp > $now - 60));
if (count($recentRequests) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'Please wait a moment before sending another question.']);
    exit();
}
$recentRequests[] = $now;
$_SESSION['assistant_requests'] = $recentRequests;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    http_response_code(413);
    echo json_encode(['error' => 'The request is too large.']);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Send a valid question.']);
    exit();
}
$role = $body['role'] ?? '';
if (!in_array($role, ['patient', 'doctor'], true) || !medconnect_role_authenticated($role)) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to the correct workspace to use the health assistant.']);
    exit();
}
$assistantSessionId = medconnect_assistant_session_id($role);
$clientSessionId = (string)($body['session_id'] ?? '');
if ($clientSessionId !== '' && !hash_equals($assistantSessionId, $clientSessionId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Your assistant session has changed. Refresh the dashboard and try again.']);
    exit();
}
$question = trim((string)($body['question'] ?? ''));
if ($question === '' || strlen($question) > 2000) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter a question of up to 2,000 characters.']);
    exit();
}

if ($role === 'patient' && preg_match('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?[\'’]s\b.{0,60}\b(readings?|vitals?|health\s+records?|medical\s+records?|prescriptions?|medications?)\b/i', $question)) {
    http_response_code(403);
    echo json_encode(['error' => 'The patient workspace can only show records for the signed-in patient.']);
    exit();
}

if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening)[!.?,\s]*$/i', $question)) {
    echo json_encode([
        'reply' => 'Hello! I can help you review your stored readings or answer questions from the approved MedConnect health information.',
        'sources' => [],
    ]);
    exit();
}

if (preg_match('/^(is this which step|which step is this|what step is this)[?.!,\s]*$/i', $question)) {
    echo json_encode([
        'reply' => 'Could you tell me which MedConnect feature or process you are asking about? For example, I can explain booking an appointment, reviewing readings, AI Analytics, or prescriptions.',
        'sources' => [],
    ]);
    exit();
}

require_once __DIR__ . '/includes/sms_service.php';
medconnect_load_env_file();
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/assistant_access.php';
require_once __DIR__ . '/includes/assistant_response.php';

if ($role === 'doctor') {
    $approvedDoctor = $conn->prepare("SELECT 1 FROM doctors WHERE id = :doctor_id AND verification_status = 'approved' LIMIT 1");
    $approvedDoctor->execute([':doctor_id' => (int) $_SESSION['auth']['doctor']['id']]);
    if (!$approvedDoctor->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Doctor access is pending verification.']);
        exit();
    }
}

if (preg_match('/\b(book|schedule|make|create)\b.{0,40}\b(appoin\w*|booking)\b/i', $question)) {
    echo json_encode([
        'reply' => 'To book a new appointment, use the Book an Appointment form on this dashboard. Select a department, choose an approved doctor, then enter the date and time before submitting.',
        'sources' => [],
    ]);
    exit();
}

if (preg_match('/\b(appoin\w*|scheduled|booking|bookings)\b/i', $question)) {
    if ($role === 'patient') {
        $appointmentStmt = $conn->prepare(
            'SELECT a.appointment_date, a.appointment_time, a.status, d.fullname AS doctor_name, d.department
             FROM appointments a
             JOIN doctors d ON d.id = a.doctor_id
             WHERE a.patient_id = :patient_id
             ORDER BY a.appointment_date DESC, a.appointment_time DESC
             LIMIT 20'
        );
        $appointmentStmt->execute([':patient_id' => (int)$_SESSION['auth']['patient']['id']]);
    } else {
        $appointmentStmt = $conn->prepare(
            'SELECT a.appointment_date, a.appointment_time, a.status, p.fullname AS patient_name
             FROM appointments a
             JOIN patients p ON p.id = a.patient_id
             WHERE a.doctor_id = :doctor_id
             ORDER BY a.appointment_date DESC, a.appointment_time DESC
             LIMIT 20'
        );
        $appointmentStmt->execute([':doctor_id' => (int)$_SESSION['auth']['doctor']['id']]);
    }
    $appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$appointments) {
        echo json_encode(['reply' => 'No appointments were found.', 'sources' => []]);
        exit();
    }

    $reply = count($appointments) . ' appointment' . (count($appointments) === 1 ? '' : 's') . " found:\n";
    foreach ($appointments as $appointment) {
        $date = $appointment['appointment_date'];
        $time = substr((string)$appointment['appointment_time'], 0, 5);
        $status = ucfirst((string)$appointment['status']);
        if ($role === 'patient') {
            $reply .= "\n{$date} at {$time} with {$appointment['doctor_name']} ({$appointment['department']}) — {$status}.";
        } else {
            $reply .= "\n{$date} at {$time} for {$appointment['patient_name']} — {$status}.";
        }
    }
    echo json_encode(['reply' => $reply, 'sources' => []]);
    exit();
}

if (preg_match('/\b(prescription|prescriptions|medication|medications|medicine)\b/i', $question)) {
    if ($role === 'patient') {
        $prescriptionStmt = $conn->prepare(
            'SELECT pp.medication, pp.dosage, pp.instructions, pp.prescription, pp.created_at, d.fullname AS doctor_name
             FROM patient_prescriptions pp
             JOIN doctors d ON d.id = pp.doctor_id
             WHERE pp.patient_id = :patient_id
             ORDER BY pp.created_at DESC
             LIMIT 20'
        );
        $prescriptionStmt->execute([':patient_id' => (int)$_SESSION['auth']['patient']['id']]);
    } else {
        $prescriptionStmt = $conn->prepare(
            'SELECT pp.medication, pp.dosage, pp.instructions, pp.prescription, pp.created_at, p.fullname AS patient_name
             FROM patient_prescriptions pp
             JOIN patients p ON p.id = pp.patient_id
             WHERE pp.doctor_id = :doctor_id
             ORDER BY pp.created_at DESC
             LIMIT 20'
        );
        $prescriptionStmt->execute([':doctor_id' => (int)$_SESSION['auth']['doctor']['id']]);
    }
    $prescriptions = $prescriptionStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$prescriptions) {
        echo json_encode(['reply' => 'No prescriptions were found.', 'sources' => []]);
        exit();
    }

    $reply = count($prescriptions) . ' prescription' . (count($prescriptions) === 1 ? '' : 's') . " found:\n";
    foreach ($prescriptions as $prescription) {
        $label = $prescription['medication'] ?: $prescription['prescription'];
        $details = $prescription['dosage'] ? ", {$prescription['dosage']}" : '';
        $date = substr((string)$prescription['created_at'], 0, 10);
        if ($role === 'patient') {
            $reply .= "\n{$label}{$details} from {$prescription['doctor_name']} ({$date}).";
        } else {
            $reply .= "\n{$label}{$details} for {$prescription['patient_name']} ({$date}).";
        }
    }
    echo json_encode(['reply' => $reply, 'sources' => []]);
    exit();
}

if (preg_match('/\b(health\s+analytics?|analytics|health\s+insights?)\b/i', $question)) {
    echo json_encode([
        'reply' => 'Health Analytics reviews recent stored readings for pattern differences and displays an informational screening result. It is not a diagnosis, emergency alert, or prescription. Open AI Analytics from the dashboard to view it.',
        'sources' => [],
    ]);
    exit();
}

if (preg_match('/\b(suggest|uggest|recommend|which|what)\b.{0,50}\bdepartment\b/i', $question)
    || preg_match('/\b(heart|cardiac|cardiology|chest|heartbeat|blood pressure|ear|hearing)\b.{0,35}\brelated\b.{0,30}\bdepartment\b/i', $question)
    || preg_match('/\b(fever|high temperature|flu|cold)\b/i', $question)) {
    if (preg_match('/\b(ear|hearing)\b/i', $question) && !preg_match('/\b(heart|cardiac|cardiology|chest|heartbeat)\b/i', $question)) {
        $reply = 'For ear- or hearing-related concerns, ENT is the relevant department.';
    } elseif (preg_match('/\b(heart|cardiac|cardiology|chest|heartbeat|blood pressure)\b/i', $question)) {
        $reply = 'For heart-related concerns, Cardiology is the relevant department. If you have severe or sudden chest pain, difficulty breathing, fainting, or other emergency symptoms, contact local emergency services immediately.';
    } elseif (preg_match('/\b(fever|high temperature|flu|cold)\b/i', $question)) {
        $reply = 'For fever or flu-like symptoms, General Medicine is a suitable department. Seek urgent medical care for severe breathing difficulty, confusion, a seizure, dehydration, or a very high or persistent fever.';
    } else {
        $reply = 'Tell me which symptom or health concern you want help with, and I can suggest the most relevant department.';
    }
    echo json_encode(['reply' => $reply, 'sources' => []]);
    exit();
}

if (preg_match('/\b(doctor|doctors|available|availability|department|departments)\b/i', $question)) {
    $doctorStmt = $conn->query(
        "SELECT DISTINCT fullname, department
         FROM doctors
         WHERE verification_status = 'approved'
         ORDER BY department, fullname"
    );
    $availableDoctors = $doctorStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$availableDoctors) {
        echo json_encode(['reply' => 'No approved doctors are currently available.', 'sources' => []]);
        exit();
    }

    $departments = [];
    foreach ($availableDoctors as $doctor) {
        $department = trim((string)$doctor['department']) ?: 'General';
        $departments[$department][] = $doctor['fullname'];
    }
    if (preg_match('/\bdepartment|departments\b/i', $question) && !preg_match('/\bdoctor|doctors\b/i', $question)) {
        echo json_encode([
            'reply' => count($departments) . ' departments are available: ' . implode(', ', array_keys($departments)) . '.',
            'sources' => [],
        ]);
        exit();
    }

    $reply = count($availableDoctors) . ' approved doctor' . (count($availableDoctors) === 1 ? '' : 's') . " available:\n";
    foreach ($departments as $department => $doctors) {
        $reply .= "\n{$department}: " . implode(', ', $doctors) . '.';
    }
    echo json_encode(['reply' => $reply, 'sources' => []]);
    exit();
}

// Structured measurement questions are answered from the authorized record, not Gemini.
$measurementTerms = '(heart\s*rate|heart-rate|spo2|oxygen|temperature|blood pressure|blood-pressure|readings?|measurements?|vitals?|pulse|bpm|\bhr\b|\bbp\b)';
$measurementIntent = preg_match('/\b(latest|last|recent|recorded|reading|measurement|today|yesterday|week|seven|timestamp|summari[sz]e|average|mean|count|how many|show|list|compare|change|missing|complete|gap|history|available|included|fields?|unit|values?|pulse|bpm|hr|bp)\b/i', $question);
if (preg_match("/{$measurementTerms}/i", $question) && $measurementIntent) {
    $targetId = null;
    if ($role === 'patient') {
        $targetId = (int)$_SESSION['auth']['patient']['id'];
    } else {
        $targetId = filter_var($body['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$targetId || empty($_SESSION['auth']['doctor']['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Choose an assigned patient before asking about readings.']);
            exit();
        }
        if (!medconnect_doctor_can_access_patient($conn, (int)$_SESSION['auth']['doctor']['id'], $targetId)) {
            http_response_code(403);
            echo json_encode(['error' => 'You are not authorized to view that patient\'s readings.']);
            exit();
        }
    }

    $dateFilter = 'recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $periodLabel = 'the last seven days';
    $explicitRecentPeriod = preg_match('/\b(today|yesterday|this week|last seven|last 7|seven days?)\b/i', $question);
    if (!$explicitRecentPeriod && preg_match('/\b(latest|most recent|last)\b/i', $question) && !preg_match('/\b(previous|last two)\b/i', $question)) {
        $dateFilter = '1=1';
        $periodLabel = 'the available history';
    } elseif (!$explicitRecentPeriod && preg_match('/\b(compare|previous|last two|change)\b/i', $question)) {
        $dateFilter = '1=1';
        $periodLabel = 'the available history';
    } elseif (preg_match('/\btoday\b/i', $question)) {
        $dateFilter = 'DATE(recorded_at) = CURDATE()';
        $periodLabel = 'today';
    } elseif (preg_match('/\byesterday\b/i', $question)) {
        $dateFilter = 'DATE(recorded_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
        $periodLabel = 'yesterday';
    } elseif (preg_match('/\b(past|older|previous|before\s*seven(?:days?)?|before\s*7(?:days?)?)\b/i', $question)) {
        $dateFilter = 'recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $periodLabel = 'before the last seven days';
    }
    $stmt = $conn->prepare("SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at FROM health_metrics WHERE patient_id = :patient_id AND {$dateFilter} ORDER BY recorded_at DESC, id DESC LIMIT 50");
    $stmt->execute([':patient_id' => $targetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $periodText = $periodLabel === 'today' ? 'today' : "in {$periodLabel}";
        $reply = "No readings were recorded for this patient {$periodText}.";
        if (preg_match('/\\b(what does|meaning of|explain)\\b.{0,40}\\b(spo2|oxygen)\\b/i', $question)) {
            $reply .= " SpO2 is an estimate of the percentage of oxygen-saturated hemoglobin in the blood; this explanation is general information, not a diagnosis.";
        }
        $reply = medconnect_append_spo2_explanation($question, $reply);
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }

    $fields = [
        'heart_rate' => ['heart\s*-?\s*rate', 'heart rate', 'BPM'],
        'spo2' => ['spo2|oxygen', 'SpO2', '%'],
        'temperature' => ['temperature', 'temperature', '°C'],
        'blood_pressure' => ['blood\s*-?\s*pressure', 'blood pressure', 'mmHg'],
    ];
    $latest = $rows[0];
    if (preg_match('/\\b(what does|meaning of|explain)\\b.{0,40}\\b(spo2|oxygen)\\b/i', $question)) {
        $reply = 'Your latest stored reading (' . $latest['recorded_at'] . '): heart rate ' . ($latest['heart_rate'] ?? 'not recorded')
            . ' BPM, SpO2 ' . ($latest['spo2'] ?? 'not recorded') . '%, temperature ' . ($latest['temperature'] ?? 'not recorded')
            . ' °C, and blood pressure ' . ($latest['blood_pressure_sys'] ?? 'not recorded') . '/'
            . ($latest['blood_pressure_dia'] ?? 'not recorded') . ' mmHg. SpO2 is an estimate of the percentage of oxygen-saturated hemoglobin in the blood. These stored values and this general explanation are not a diagnosis.';
        $reply = medconnect_append_spo2_explanation($question, $reply);
        echo json_encode(['reply' => $reply, 'sources' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $fieldKey = null;
    foreach ($fields as $key => [$pattern]) {
        if (preg_match("/{$pattern}/i", $question)) {
            $fieldKey = $key;
            break;
        }
    }
    $valueFor = static function (array $row, string $key): ?string {
        if ($key === 'blood_pressure') {
            if ($row['blood_pressure_sys'] === null && $row['blood_pressure_dia'] === null) return null;
            return ($row['blood_pressure_sys'] ?? '—') . '/' . ($row['blood_pressure_dia'] ?? '—') . ' mmHg';
        }
        $map = ['heart_rate' => ['heart_rate', 'BPM'], 'spo2' => ['spo2', '%'], 'temperature' => ['temperature', '°C']];
        return $row[$map[$key][0]] === null ? null : $row[$map[$key][0]] . ' ' . $map[$key][1];
    };

    if (!$fieldKey && preg_match('/\b(latest|today|yesterday|most recent|all available|which measurements)\b/i', $question)) {
        $reply = count($rows) . " reading" . (count($rows) === 1 ? '' : 's') . " found for {$periodLabel}. Latest record ({$latest['recorded_at']}): "
            . 'HR ' . ($latest['heart_rate'] ?? 'not recorded') . ' BPM, SpO2 ' . ($latest['spo2'] ?? 'not recorded') . '%, temperature ' . ($latest['temperature'] ?? 'not recorded') . ' °C, blood pressure ' . ($latest['blood_pressure_sys'] ?? '—') . '/' . ($latest['blood_pressure_dia'] ?? '—') . ' mmHg.';
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }
    if ($fieldKey && preg_match('/\b(latest|last|most recent|when|timestamp)\b/i', $question)
        && !preg_match('/\b(compare|change|previous|last two)\b/i', $question)) {
        $value = $valueFor($latest, $fieldKey);
        $label = $fields[$fieldKey][1];
        $reply = $value === null
            ? "No {$label} value was recorded in {$periodLabel}."
            : "Latest {$label}: {$value}, recorded at {$latest['recorded_at']}.";
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }
    if (preg_match('/\b(missing|complete|included|available values|fields)\b/i', $question)) {
        $missing = [];
        foreach (['heart_rate' => 'heart rate', 'spo2' => 'SpO2', 'temperature' => 'temperature', 'blood_pressure_sys' => 'blood pressure'] as $column => $label) {
            if ($column === 'blood_pressure_sys' ? ($latest['blood_pressure_sys'] === null || $latest['blood_pressure_dia'] === null) : $latest[$column] === null) $missing[] = $label;
        }
        $reply = $missing ? 'The latest record is missing: ' . implode(', ', $missing) . '.' : 'The latest record includes heart rate, SpO2, temperature, and blood pressure.';
        echo json_encode(['reply' => $reply . " Recorded at {$latest['recorded_at']}.", 'sources' => []]);
        exit();
    }
    if (preg_match('/\b(average|mean|summari[sz]e)\b/i', $question)) {
        $reply = "Summary for {$periodLabel} (" . count($rows) . " readings):";
        foreach (['heart_rate' => ['heart rate', 'BPM'], 'spo2' => ['SpO2', '%'], 'temperature' => ['temperature', '°C']] as $key => [$label, $unit]) {
            $values = array_values(array_filter(array_map(static fn($row) => $row[$key] === null ? null : (float)$row[$key], $rows), static fn($value) => $value !== null));
            $reply .= $values ? sprintf("\n%s average: %.1f %s (%d readings).", $label, array_sum($values) / count($values), $unit, count($values)) : "\n{$label}: no values recorded.";
        }
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }
    if (preg_match('/\b(compare|change|previous|last two)\b/i', $question) && count($rows) > 1) {
        $previous = $rows[1];
        $reply = "Latest ({$latest['recorded_at']}) compared with previous ({$previous['recorded_at']}):";
        foreach (['heart_rate' => ['heart rate', 'BPM'], 'spo2' => ['SpO2', '%'], 'temperature' => ['temperature', '°C']] as $key => [$label, $unit]) {
            if ($latest[$key] !== null && $previous[$key] !== null) $reply .= sprintf("\n%s: %+.1f %s.", $label, (float)$latest[$key] - (float)$previous[$key], $unit);
        }
        if ($latest['blood_pressure_sys'] !== null && $previous['blood_pressure_sys'] !== null) $reply .= sprintf("\nBlood pressure systolic: %+.1f mmHg.", (float)$latest['blood_pressure_sys'] - (float)$previous['blood_pressure_sys']);
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }
    if (preg_match('/\b(how many|count|number of)\b/i', $question)) {
        echo json_encode(['reply' => count($rows) . " readings were recorded in {$periodLabel}.", 'sources' => []]);
        exit();
    }
    if (preg_match('/\b(show|list|history|readings|measurements|values|timestamps)\b/i', $question)) {
        $reply = "Recent readings for {$periodLabel}:\n";
        foreach (array_slice($rows, 0, 10) as $row) $reply .= $row['recorded_at'] . ': HR ' . ($row['heart_rate'] ?? '—') . ' BPM, SpO2 ' . ($row['spo2'] ?? '—') . '%, temp ' . ($row['temperature'] ?? '—') . " °C, BP " . ($row['blood_pressure_sys'] ?? '—') . '/' . ($row['blood_pressure_dia'] ?? '—') . " mmHg\n";
        echo json_encode(['reply' => $reply, 'sources' => []]);
        exit();
    }
}

// Patient-record questions are answered from scoped SQL only. No readings or identifiers go to Gemini.
$isHistoricalQuestion = preg_match('/\b(past|older|previous|history|before\s*seven(?:days?)?|before\s*7(?:days?)?)\b/i', $question);
$isDataQuestion = preg_match('/\b(latest|recent|readings?|measurements?|vitals?|trend|compare|show me|what are my|what is my)\b/i', $question)
    || preg_match('/\bmy\b.{0,60}\b(heart\s*rate|spo2|oxygen|temperature|blood pressure|readings?|vitals?|last|hr|bp|pulse|bpm)\b/i', $question)
    || preg_match('/\bsummari[sz]e\b.{0,60}\b(patient|my|readings?|vitals?|last seven|last 7)\b/i', $question)
    || $isHistoricalQuestion;
if ($isDataQuestion) {
    $targetId = null;
    if ($role === 'patient') {
        $targetId = (int)$_SESSION['auth']['patient']['id'];
    } else {
        $targetId = filter_var($body['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$targetId || empty($_SESSION['auth']['doctor']['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Choose an assigned patient before asking about readings.']);
            exit();
        }
        if (!medconnect_doctor_can_access_patient($conn, (int)$_SESSION['auth']['doctor']['id'], $targetId)) {
            http_response_code(403);
            echo json_encode(['error' => 'You are not authorized to view that patient\'s readings.']);
            exit();
        }
    }
    $dateCondition = $isHistoricalQuestion
        ? 'recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        : 'recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $stmt = $conn->prepare("SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at FROM health_metrics WHERE patient_id = :patient_id AND {$dateCondition} ORDER BY recorded_at DESC, id DESC LIMIT 50");
    $stmt->execute([':patient_id' => $targetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $period = $isHistoricalQuestion ? 'before the last seven days' : 'in the last seven days';
        $reply = "No readings were recorded for this patient {$period}.";
        if (preg_match('/\b(what does|meaning of|explain)\b.{0,50}\b(spo2|oxygen)\b/i', $question)) {
            $reply .= ' SpO2 is an estimate of the percentage of oxygen-saturated hemoglobin in the blood; this explanation is general information, not a diagnosis.';
        }
        $reply = medconnect_append_spo2_explanation($question, $reply);
        echo json_encode(['reply' => $reply, 'sources' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $latest = $rows[0];
    $period = $isHistoricalQuestion ? 'before the last seven days' : 'the last seven days';
    $reply = "Latest recorded reading in {$period} (" . $latest['recorded_at'] . "): heart rate " . ($latest['heart_rate'] ?? 'not recorded') . " BPM; SpO2 " . ($latest['spo2'] ?? 'not recorded') . "% ; temperature " . ($latest['temperature'] ?? 'not recorded') . " °C; blood pressure " . ($latest['blood_pressure_sys'] ?? 'not recorded') . "/" . ($latest['blood_pressure_dia'] ?? 'not recorded') . " mmHg.\n\n" . count($rows) . " readings are available from {$period}. These are stored measurements, not a diagnosis.";
    $metrics = ['heart_rate' => ['Heart rate', 'BPM'], 'spo2' => ['SpO2', '%'], 'temperature' => ['Temperature', '°C']];
    if (preg_match('/\b(summar|trend|average|mean)\w*\b/i', $question)) {
        $reply .= "\nSeven-day summary (available readings only):";
        foreach ($metrics as $column => [$label, $unit]) {
            $values = array_values(array_filter(array_map(static fn($row) => $row[$column] === null ? null : (float)$row[$column], $rows), static fn($value) => $value !== null));
            $reply .= $values
                ? sprintf("\n%s average: %.1f %s (%d readings).", $label, array_sum($values) / count($values), $unit, count($values))
                : "\n{$label}: no readings available.";
        }
    }
    if (preg_match('/\b(compare|previous|change|trend)\w*\b/i', $question)) {
        if (count($rows) > 1) {
            $reply .= "\nCompared with the previous reading (" . $rows[1]['recorded_at'] . "):";
            foreach ($metrics as $column => [$label, $unit]) {
                if ($latest[$column] !== null && $rows[1][$column] !== null) {
                    $reply .= sprintf("\n%s: %+.1f %s.", $label, (float)$latest[$column] - (float)$rows[1][$column], $unit);
                }
            }
        } else {
            $reply .= "\nA previous reading is not available for comparison.";
        }
    }
    if (preg_match('/\b(show|list|recent|readings|measurements|history|last seven|last 7)\b/i', $question)) {
        $reply .= "\nRecent readings:\n";
        foreach (array_slice($rows, 0, 10) as $row) {
            $reply .= $row['recorded_at'] . ': HR ' . ($row['heart_rate'] ?? '—') . ' BPM, SpO2 ' . ($row['spo2'] ?? '—') . '%, temp ' . ($row['temperature'] ?? '—') . " °C\n";
        }
    }
    if (preg_match('/\b(what does|meaning of|explain)\b.{0,50}\b(spo2|oxygen)\b/i', $question)) {
        $reply .= "\nSpO2 is an estimate of the percentage of oxygen-saturated hemoglobin in the blood. This general explanation is not a diagnosis.";
    }
    $reply = medconnect_append_spo2_explanation($question, $reply);
    echo json_encode(['reply' => $reply, 'sources' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

$serviceToken = getenv('RAG_SERVICE_TOKEN');
$ragUrl = getenv('RAG_URL') ?: 'http://127.0.0.1:8000/chat';
if (!$serviceToken || !function_exists('curl_init')) {
    http_response_code(503);
    echo json_encode(['error' => 'The approved-document assistant is not configured.']);
    exit();
}
$payload = json_encode([
    'question' => $question,
    'request_id' => bin2hex(random_bytes(16)),
    'conversation_id' => $assistantSessionId,
]);
$curl = curl_init($ragUrl);
@set_time_limit(75);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-MedConnect-Token: ' . $serviceToken],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 70,
]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$result = json_decode($response ?: '', true);
if ($status < 200 || $status >= 300 || !is_array($result) || !isset($result['reply'])) {
    http_response_code(503);
    echo json_encode(['error' => 'The assistant is temporarily unavailable. You can still use the rest of MedConnect.']);
    exit();
}
echo json_encode(['reply' => $result['reply'], 'sources' => $result['sources'] ?? []]);

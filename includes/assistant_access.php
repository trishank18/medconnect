<?php

/** Check the existing doctor assignment/appointment rule for an assistant record query. */
function medconnect_doctor_can_access_patient(PDO $conn, int $doctorId, int $patientId): bool
{
    if ($doctorId < 1 || $patientId < 1) {
        return false;
    }

    $access = $conn->prepare(
        "SELECT 1 FROM patients p JOIN doctors d ON d.id = :doctor_id
         WHERE p.id = :patient_id AND d.verification_status = 'approved'
           AND (p.doctor_id = d.id OR EXISTS (
               SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = d.id
           )) LIMIT 1"
    );
    $access->execute([':doctor_id' => $doctorId, ':patient_id' => $patientId]);
    return $access->fetchColumn() !== false;
}

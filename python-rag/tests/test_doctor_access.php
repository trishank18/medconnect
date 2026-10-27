<?php
require_once dirname(__DIR__, 2) . '/includes/assistant_access.php';
require_once dirname(__DIR__, 2) . '/includes/assistant_response.php';

final class AssistantAccessStatement extends PDOStatement
{
    private bool $authorized;
    public array $params = [];

    public function __construct(bool $authorized) { $this->authorized = $authorized; }
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->authorized ? 1 : false; }
}

final class AssistantAccessPDO extends PDO
{
    public string $query = '';
    public ?AssistantAccessStatement $statement = null;
    public function __construct(private bool $authorized) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->query = $query;
        $this->statement = new AssistantAccessStatement($this->authorized);
        return $this->statement;
    }
}

$allowed = new AssistantAccessPDO(true);
if (!medconnect_doctor_can_access_patient($allowed, 7, 23)) {
    fwrite(STDERR, "Assigned patient should be permitted.\n"); exit(1);
}
if (($allowed->statement?->params ?? []) !== [':doctor_id' => 7, ':patient_id' => 23]) {
    fwrite(STDERR, "Doctor and patient IDs were not bound as parameters.\n"); exit(1);
}
if (!str_contains($allowed->query, "d.verification_status = 'approved'")
    || !str_contains($allowed->query, 'p.doctor_id = d.id')
    || !str_contains($allowed->query, 'EXISTS')) {
    fwrite(STDERR, "Authorization query is missing an existing access rule.\n"); exit(1);
}

$denied = new AssistantAccessPDO(false);
if (medconnect_doctor_can_access_patient($denied, 7, 24)) {
    fwrite(STDERR, "Unassigned patient should be denied.\n"); exit(1);
}
if (medconnect_doctor_can_access_patient($denied, 0, 24)
    || medconnect_doctor_can_access_patient($denied, 7, 0)) {
    fwrite(STDERR, "Invalid identifiers should be denied.\n"); exit(1);
}

$mixedReply = medconnect_append_spo2_explanation(
    'Explain my health readings and what SpO2 means.',
    'Latest recorded reading: SpO2 98%.'
);
if (!str_contains($mixedReply, 'SpO2 98%') || !str_contains($mixedReply, 'oxygen-saturated hemoglobin')
    || !str_contains($mixedReply, 'not a diagnosis')) {
    fwrite(STDERR, "Mixed stored-reading and SpO2 explanation was incomplete.\n"); exit(1);
}

echo "PHP assistant safety checks passed.\n";

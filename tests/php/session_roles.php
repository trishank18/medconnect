<?php
declare(strict_types=1);

$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medconnect-role-session-test';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create isolated session test storage.');
}
session_save_path($sessionPath);
session_name('MEDCONNECT_ROLE_TEST');
session_id('role-test-' . bin2hex(random_bytes(8)));
session_start();
require_once dirname(__DIR__, 2) . '/includes/auth.php';

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Existing users with a pre-change session retain their authenticated account.
$_SESSION['doctor_id'] = 101;
$_SESSION['doctor_name'] = 'Doctor Test';
$_SESSION['admin_authenticated'] = true;
$_SESSION['admin_username'] = 'admin-test';
medconnect_migrate_legacy_auth_session();
expect_true(medconnect_role_authenticated('doctor'), 'Legacy doctor session was not migrated.');
expect_true(medconnect_role_authenticated('admin'), 'Legacy admin session was not migrated.');

// Logging into one role must preserve all other role identities.
medconnect_login_role('doctor', ['id' => 101, 'name' => 'Doctor Test', 'username' => 'doctor-test']);
expect_true(medconnect_role_user_id('doctor') === 101, 'Doctor login failed to set doctor identity.');
expect_true(medconnect_role_authenticated('admin'), 'Doctor login replaced admin identity.');
medconnect_login_role('patient', ['id' => 202, 'name' => 'Patient Test', 'username' => 'patient-test']);
expect_true(medconnect_role_user_id('doctor') === 101, 'Patient login replaced doctor identity.');
expect_true(medconnect_role_user_id('patient') === 202, 'Patient login failed to set patient identity.');
expect_true(medconnect_role_authenticated('admin'), 'Patient login replaced admin identity.');

medconnect_login_role('admin', ['id' => 1, 'username' => 'admin-test']);
expect_true(medconnect_role_user_id('doctor') === 101, 'Admin login replaced doctor identity.');
expect_true(medconnect_role_user_id('patient') === 202, 'Admin login replaced patient identity.');
expect_true(medconnect_role_authenticated('admin'), 'Admin login failed.');

medconnect_clear_role_auth('doctor');
session_regenerate_id(true);
expect_true(!medconnect_role_authenticated('doctor'), 'Doctor logout did not clear doctor identity.');
expect_true(medconnect_role_user_id('patient') === 202, 'Doctor logout cleared patient identity.');
expect_true(medconnect_role_authenticated('admin'), 'Doctor logout cleared admin identity.');

medconnect_clear_role_auth('patient');
session_regenerate_id(true);
expect_true(!medconnect_role_authenticated('patient'), 'Patient logout did not clear patient identity.');
expect_true(medconnect_role_authenticated('admin'), 'Patient logout cleared admin identity.');

medconnect_clear_role_auth('admin');
session_regenerate_id(true);
expect_true(!medconnect_role_authenticated('admin'), 'Admin logout did not clear admin identity.');
expect_true(!medconnect_role_authenticated('unknown'), 'Unknown role was accepted.');

// A role expires after 90 minutes idle, while activity in another role does not refresh it.
medconnect_login_role('doctor', ['id' => 303, 'name' => 'Idle Doctor', 'username' => 'idle-doctor']);
medconnect_login_role('patient', ['id' => 404, 'name' => 'Active Patient', 'username' => 'active-patient']);
$_SESSION['auth']['doctor']['last_activity_at'] = time() - MEDCONNECT_ROLE_IDLE_TIMEOUT_SECONDS;
expect_true(medconnect_role_authenticated('patient'), 'Patient session expired before its own idle timeout.');
expect_true(!medconnect_role_authenticated('doctor'), 'Idle doctor session remained authenticated after 90 minutes.');
expect_true(medconnect_role_authenticated('patient'), 'Doctor expiry incorrectly cleared patient authentication.');
$_SESSION['auth']['patient']['last_activity_at'] = time() - MEDCONNECT_ROLE_IDLE_TIMEOUT_SECONDS - 1;
expect_true(!medconnect_role_authenticated('patient'), 'Idle patient session remained authenticated after 90 minutes.');

session_write_close();
echo "PASS: role-scoped login/logout, legacy migration, and independent 90-minute idle expiry\n";

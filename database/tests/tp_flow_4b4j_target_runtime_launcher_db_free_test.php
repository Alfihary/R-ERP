<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$launcher = (string) file_get_contents($base . '/database/manual/tp_flow_4b4j_run_target_runtime.ps1');
$preflight = (string) file_get_contents($base . '/database/tp_flow_4b4j_target_runtime_preflight.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($launcher, 'r_erp_db_core_0_test'), 'TARGET_DATABASE_GUARD_MISSING');
$assert(str_contains($launcher, 'APP_DB_READ_USER'), 'READ_OVERRIDE_MISSING');
$assert(str_contains($launcher, 'APP_DB_WRITE_USER'), 'WRITE_OVERRIDE_MISSING');
$assert(str_contains($launcher, 'APP_DB_WRITE_PASSWORD'), 'WRITE_SECRET_OVERRIDE_MISSING');
$assert(str_contains($launcher, 'TARGET_DATABASE_CONFIRMATION_REQUIRED'), 'TARGET_CONFIRMATION_GUARD_MISSING');
$assert(str_contains($launcher, 'ROOT_RUNTIME_FORBIDDEN'), 'ROOT_GUARD_MISSING');
$assert(str_contains($launcher, 'QA_SETUP_RUNTIME_FORBIDDEN'), 'QA_SETUP_GUARD_MISSING');
$assert(!str_contains($launcher, 'r_erp_tickets_qa_2f'), 'QA_DATABASE_LITERAL_FORBIDDEN');
$assert(!str_contains($launcher, 'r_erp_tickets_qa_setup'), 'QA_SETUP_LITERAL_FORBIDDEN');
$assert(!preg_match('/APP_DB_(?:READ_)?PASSWORD\s*=\s*["\'][^"\']+["\']/', $launcher), 'PASSWORD_LITERAL_PRESENT');
$assert(!str_contains($launcher, '.env ='), 'ENV_MUTATION_FORBIDDEN');

$assert(str_contains($preflight, "SELECT DATABASE()"), 'DATABASE_IDENTITY_SELECT_MISSING');
$assert(str_contains($preflight, "CURRENT_USER()"), 'CURRENT_USER_SELECT_MISSING');
$assert(str_contains($preflight, 'SHOW GRANTS'), 'GRANTS_PREFLIGHT_MISSING');
$assert(str_contains($preflight, 'ResponsibleRecipientResolver'), 'REAL_RESOLVER_NOT_USED');
$assert(str_contains($preflight, 'ResponsibleRecipientRepository'), 'REAL_REPOSITORY_NOT_USED');
$assert(str_contains($preflight, 'r_erp_db_core_0_test'), 'PREFLIGHT_TARGET_MISSING');
$assert(str_contains($preflight, 'r_erp_tickets_write@localhost'), 'WRITE_IDENTITY_GUARD_MISSING');
$assert(str_contains($preflight, 'consulta@localhost'), 'READ_IDENTITY_GUARD_MISSING');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)\b/i', $preflight), 'PREFLIGHT_DML_OR_DDL_PRESENT');
$assert(!str_contains($preflight, 'MailOutboxProcessor'), 'WORKER_FORBIDDEN');
$assert(!str_contains($preflight, 'SMTP'), 'SMTP_FORBIDDEN');
$assert(!preg_match('/\b4612\b|\b4613\b|\b1001\b|\b698\b/', $launcher), 'QA_LITERAL_IN_LAUNCHER_FORBIDDEN');

echo "TP_FLOW_4B4J_TARGET_RUNTIME_LAUNCHER_DB_FREE=PASS\n";

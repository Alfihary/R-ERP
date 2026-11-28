<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$harness = $base . '/database/tp_flow_4b4m_functional_target_runtime.php';
$launcher = $base . '/database/manual/tp_flow_4b4j_run_target_runtime.ps1';

foreach ([$harness, $launcher] as $file) {
    if (!is_file($file)) { throw new RuntimeException('missing:' . $file); }
}
$source = file_get_contents($harness);
$launcherSource = file_get_contents($launcher);
foreach ([
    "'--preflight'", "'--grants'", "'--smoke'", "'--full'", 'ReadConnectionProvider',
    'WriteConnectionProvider', 'ResponsibleRecipientResolver',
    'ProductRequestTicketService', 'ProductTicketEmailNotificationService',
    'ProductTicketEmailOutboxService', 'try {', 'finally {',
    'tickets_productos_correos', 'series_documentales', 'DELETE FROM', 'r_erp_db_core_0_test',
    'consulta@localhost', 'r_erp_tickets_write@localhost',
    'smtp_connections', 'worker_runtime_executed',
] as $needle) {
    if (!str_contains($source, $needle)) { throw new RuntimeException('harness_contract_missing:' . $needle); }
}
foreach ([
    '[string]$RuntimeMode', "'--' + " . '$RuntimeMode', 'TARGET_DATABASE_CONFIRMATION_REQUIRED',
    'ROOT_RUNTIME_FORBIDDEN', 'QA_SETUP_RUNTIME_FORBIDDEN',
] as $needle) {
    if (!str_contains($launcherSource, $needle)) { throw new RuntimeException('launcher_guard_missing:' . $needle); }
}
foreach (preg_split('/\R/', $source) ?: [] as $line) {
    if (preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $line)
        && preg_match('/\b(4612|4613|1001|698|root|r_erp_tickets_qa_setup)\b/i', $line)) {
        throw new RuntimeException('unsafe_fixture_identity_usage');
    }
}
if (preg_match('/\b(TRUNCATE|MailOutboxProcessor|SMTP|mail\s*\(\s*)\b/i', $source)) {
    throw new RuntimeException('forbidden_runtime_operation');
}
echo "PASS\n";

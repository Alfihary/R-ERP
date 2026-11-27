<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$assign = (string) file_get_contents($base . '/database/manual/tp_flow_4b4h_assign_qa_responsible_role.sql');
$verify = (string) file_get_contents($base . '/database/manual/tp_flow_4b4h_verify_qa_responsible_role.sql');
$cleanup = (string) file_get_contents($base . '/database/manual/tp_flow_4b4h_cleanup_qa_responsible_role.sql');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([$assign, $verify, $cleanup] as $sql) {
    $assert(str_contains($sql, 'r_erp_db_core_0_test'), 'TARGET_DATABASE_GUARD_MISSING');
    $assert(!preg_match('/password\s*[:=]\s*[^\s-]/i', $sql), 'CREDENTIAL_LITERAL_PRESENT');
    $assert(!str_contains($sql, 'CREATE ROLE'), 'ROLE_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE TABLE'), 'SCHEMA_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'INSERT INTO roles'), 'MASTER_ROLE_INSERT_FORBIDDEN');
    $assert(!str_contains($sql, 'INSERT INTO permisos'), 'MASTER_PERMISSION_INSERT_FORBIDDEN');
    $assert(!str_contains($sql, 'INSERT INTO rol_permisos'), 'ROLE_PERMISSION_MUTATION_FORBIDDEN');
    $assert(!str_contains($sql, 'GRANT '), 'GRANT_FORBIDDEN');
    $assert(str_contains($sql, 'tickets_productos.resolver'), 'RESOLVER_PERMISSION_GUARD_MISSING');
}

$assert(str_contains($assign, 'SELECT DATABASE()'), 'ASSIGN_DATABASE_PRECHECK_MISSING');
$assert(str_contains($assign, 'SELECT CURRENT_USER()'), 'ASSIGN_IDENTITY_PRECHECK_MISSING');
$assert(str_contains($assign, 'START TRANSACTION'), 'ASSIGN_TRANSACTION_MISSING');
$assert(str_contains($assign, '@qa_requester_id = 4612'), 'REQUESTER_GUARD_MISSING');
$assert(str_contains($assign, '@qa_responsible_id = 4613'), 'RESPONSIBLE_GUARD_MISSING');
$assert(str_contains($assign, 'r.es_sistema = 0'), 'SYSTEM_ROLE_EXCLUSION_MISSING');
$assert(str_contains($assign, "UPPER(r.codigo) <> 'ADMIN'"), 'ADMIN_ROLE_EXCLUSION_MISSING');
$assert(str_contains($assign, 'SET @selected_role_id = NULL'), 'DYNAMIC_ROLE_SELECTION_GUARD_MISSING');
$assert(str_contains($assign, 'SET @audit_actor_id = NULL'), 'AUDIT_ACTOR_GUARD_MISSING');
$assert(str_contains($assign, 'ROW_COUNT()'), 'ROW_COUNT_GUARD_MISSING');
$assert(str_contains($assign, 'COMMIT;'), 'COMMIT_INSTRUCTION_MISSING');
$assert(str_contains($assign, 'ROLLBACK;'), 'ROLLBACK_INSTRUCTION_MISSING');

$assert(str_contains($verify, 'SELECT DISTINCT u.id AS responsible_user_id'), 'CANONICAL_LOOKUP_MISSING');
$assert(str_contains($verify, 'u.id <> @qa_requester_id'), 'CANONICAL_ACTOR_EXCLUSION_MISSING');
$assert(str_contains($verify, 'u.id = @qa_responsible_id'), 'CANONICAL_QA_RESPONSIBLE_CHECK_MISSING');
$assert(!str_contains($verify, 'START TRANSACTION'), 'VERIFY_MUST_BE_SELECT_ONLY');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)\b/i', $verify), 'VERIFY_DML_OR_DDL_FORBIDDEN');

$assert(str_contains($cleanup, 'START TRANSACTION'), 'CLEANUP_TRANSACTION_MISSING');
$assert(str_contains($cleanup, 'WHERE usuario_id = @qa_responsible_id'), 'CLEANUP_USER_SCOPE_MISSING');
$assert(str_contains($cleanup, 'WHERE usuario_id = @qa_responsible_id\n+--   AND rol_id = @selected_role_id') || str_contains($cleanup, 'AND rol_id = @selected_role_id'), 'CLEANUP_EXACT_PK_MISSING');
$assert(!str_contains($cleanup, 'DELETE FROM roles'), 'MASTER_ROLE_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM permisos'), 'MASTER_PERMISSION_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM rol_permisos'), 'ROLE_PERMISSION_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM usuarios'), 'USER_DELETE_FORBIDDEN');
$assert(str_contains($cleanup, 'REACTIVATE_EXISTING'), 'REACTIVATION_CLEANUP_STRATEGY_MISSING');

echo "TP_FLOW_4B4H_QA_RESPONSIBLE_ROLE_SCRIPTS_DB_FREE=PASS\n";

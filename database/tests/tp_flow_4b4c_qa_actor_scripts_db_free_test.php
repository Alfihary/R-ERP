<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$create = (string) file_get_contents($base . '/database/manual/tp_flow_4b4c_create_qa_actors.sql');
$verify = (string) file_get_contents($base . '/database/manual/tp_flow_4b4c_verify_qa_actors.sql');
$cleanup = (string) file_get_contents($base . '/database/manual/tp_flow_4b4c_cleanup_qa_actors.sql');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([$create, $verify, $cleanup] as $sql) {
    $assert(str_contains($sql, 'r_erp_db_core_0_test'), 'TARGET_DATABASE_GUARD_MISSING');
    $assert(str_contains($sql, 'tpflow4b4c_requester'), 'REQUESTER_MARKER_MISSING');
    $assert(str_contains($sql, 'tpflow4b4c_responsible'), 'RESPONSIBLE_MARKER_MISSING');
    $assert(!preg_match('/password\s*[:=]\s*[^\s-]/i', $sql), 'PASSWORD_LITERAL_PRESENT');
    $assert(!str_contains($sql, 'GRANT '), 'GRANT_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE ROLE'), 'ROLE_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE PERMISSION'), 'PERMISSION_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE TABLE usuarios'), 'USER_TABLE_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE TABLE empresas'), 'COMPANY_CREATION_FORBIDDEN');
    $assert(!str_contains($sql, 'CREATE TABLE almacenes'), 'WAREHOUSE_CREATION_FORBIDDEN');
}

$assert(str_contains($create, 'START TRANSACTION'), 'CREATE_TRANSACTION_MISSING');
$assert(str_contains($create, 'LAST_INSERT_ID()'), 'CREATE_ID_CAPTURE_MISSING');
$assert(str_contains($create, 'example.invalid'), 'SYNTHETIC_EMAIL_DOMAIN_MISSING');
$assert(str_contains($create, 'usuario_empresas'), 'COMPANY_SCOPE_INSERT_MISSING');
$assert(str_contains($create, 'usuario_almacenes'), 'WAREHOUSE_SCOPE_INSERT_MISSING');
$assert(str_contains($create, 'qa_role_rows'), 'NO_ROLE_GUARD_MISSING');
$assert(str_contains($verify, 'COUNT(DISTINCT id)'), 'DISTINCT_ID_CHECK_MISSING');
$assert(str_contains($verify, 'COUNT(DISTINCT email)'), 'DISTINCT_EMAIL_CHECK_MISSING');

$cleanupDeleteOrder = [
    "DELETE ur\nFROM usuario_roles",
    "DELETE ua\nFROM usuario_almacenes",
    "DELETE ue\nFROM usuario_empresas",
    "DELETE u\nFROM usuarios",
];
$last = -1;
foreach ($cleanupDeleteOrder as $needle) {
    $position = strpos($cleanup, $needle);
    $assert($position !== false && $position > $last, 'FK_SAFE_DELETE_ORDER_INVALID');
    $last = $position;
}
$assert(str_contains($cleanup, 'SET @qa_requester_id'), 'REQUESTER_ID_CAPTURE_MISSING');
$assert(str_contains($cleanup, 'SET @qa_responsible_id'), 'RESPONSIBLE_ID_CAPTURE_MISSING');
$assert(!str_contains($cleanup, 'CREATE TEMPORARY TABLE'), 'TEMPORARY_SCHEMA_CHANGE_FORBIDDEN');
$assert(str_contains($cleanup, 'deleted_user_rows'), 'CLEANUP_ROW_GUARD_MISSING');
$assert(!str_contains($cleanup, 'DELETE FROM roles'), 'MASTER_ROLE_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM permisos'), 'MASTER_PERMISSION_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM empresas'), 'MASTER_COMPANY_DELETE_FORBIDDEN');
$assert(!str_contains($cleanup, 'DELETE FROM almacenes'), 'MASTER_WAREHOUSE_DELETE_FORBIDDEN');

echo "TP_FLOW_4B4C_QA_ACTOR_SCRIPTS_DB_FREE=PASS\n";

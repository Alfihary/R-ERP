-- TP-FLOW-4B4B manual Workbench package.
-- This script is intentionally not executed by Codex.
-- Run only on an authorized administrative Workbench connection.

SELECT DATABASE() AS target_database, CURRENT_USER() AS executing_account;

-- Required guard: DATABASE() must be exactly r_erp_db_core_0_test.
-- Stop without executing the transaction if the result is different.

SELECT
    evento,
    activo,
    mail_account_id,
    enviar_solicitante,
    enviar_responsables,
    IF(to_json IS NULL, 'NULL', 'SET') AS to_json_state,
    IF(cc_json IS NULL, 'NULL', 'SET') AS cc_json_state,
    IF(bcc_json IS NULL, 'NULL', 'SET') AS bcc_json_state
FROM tickets_productos_correo_reglas
WHERE evento IN ('COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO')
ORDER BY evento;

SELECT COUNT(*) AS target_rule_count
FROM tickets_productos_correo_reglas
WHERE evento IN ('COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO');

-- Required guard: target_rule_count must be exactly 2.
-- If it is not 2, do not continue and do not issue COMMIT.

START TRANSACTION;

UPDATE tickets_productos_correo_reglas
SET
    enviar_solicitante = 1,
    enviar_responsables = 1,
    to_json = NULL,
    cc_json = NULL,
    bcc_json = NULL
WHERE evento IN (
    'COMENTARIO_AGREGADO',
    'ADJUNTO_CARGADO'
);

SELECT ROW_COUNT() AS updated_rows;

-- Required guard: updated_rows must be exactly 2.
-- If it is not 2, execute ROLLBACK and stop. Do not execute COMMIT.

SELECT
    evento,
    activo,
    mail_account_id,
    enviar_solicitante,
    enviar_responsables,
    IF(to_json IS NULL, 'NULL', 'SET') AS to_json_state,
    IF(cc_json IS NULL, 'NULL', 'SET') AS cc_json_state,
    IF(bcc_json IS NULL, 'NULL', 'SET') AS bcc_json_state
FROM tickets_productos_correo_reglas
WHERE evento IN ('COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO')
ORDER BY evento;

-- Validate exact values above, then execute COMMIT manually.
-- On any mismatch execute ROLLBACK manually and stop.

COMMIT;

-- The six legacy rules are intentionally not updated by this package.
-- Do not insert this migration into schema_migrations manually here.
-- A later normal runner pass is safe because the UPDATE is idempotent.

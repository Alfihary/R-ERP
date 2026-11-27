-- TP-FLOW-4B4H exact cleanup package.
-- Run only after functional runtime fixtures are already clean.
-- Execute statement-by-statement in MySQL Workbench on an authorized admin connection.

SELECT DATABASE() AS target_database;
SELECT CURRENT_USER() AS executing_account;

-- Required guard: target_database must be exactly r_erp_db_core_0_test.
-- This script never deletes roles, permissions, scopes, or either QA user.

SET @qa_responsible_id = 4613;
SET @selected_role_id = NULL;
SET @qa_role_cleanup_strategy = 'INSERT_NEW';
SET @resolver_permission_code = 'tickets_productos.resolver';

-- Set @selected_role_id to the exact role id recorded by the assignment script.
-- For INSERT_NEW, this script deletes only the exact temporary PK.
-- For REACTIVATE_EXISTING, do not delete the row. Restore the exact values
-- captured before assignment using the UPDATE template below.

SELECT usuario_id, rol_id, activo, eliminado_en, eliminado_por,
       creado_por, actualizado_por
FROM usuario_roles
WHERE usuario_id = @qa_responsible_id
  AND rol_id = @selected_role_id;

START TRANSACTION;

-- INSERT_NEW cleanup branch:
-- DELETE FROM usuario_roles
-- WHERE usuario_id = @qa_responsible_id
--   AND rol_id = @selected_role_id;
-- SET @deleted_role_rows = ROW_COUNT();

-- REACTIVATE_EXISTING cleanup branch:
-- Replace the following values with the exact pre-assignment values printed by
-- the assignment precheck, then execute only this UPDATE branch:
-- UPDATE usuario_roles
-- SET activo = @previous_active,
--     eliminado_en = @previous_eliminado_en,
--     eliminado_por = @previous_eliminado_por,
--     actualizado_por = @previous_actualizado_por
-- WHERE usuario_id = @qa_responsible_id
--   AND rol_id = @selected_role_id
--   AND activo = 1;
-- SET @restored_role_rows = ROW_COUNT();

-- Required guard: exactly one branch must be executed and its row count must
-- equal 1. Otherwise execute ROLLBACK and stop.

SELECT usuario_id, rol_id, activo, eliminado_en, eliminado_por,
       creado_por, actualizado_por
FROM usuario_roles
WHERE usuario_id = @qa_responsible_id
  AND rol_id = @selected_role_id;

-- Execute COMMIT only after exact row-count and state verification.
-- COMMIT;
-- On any mismatch: ROLLBACK.

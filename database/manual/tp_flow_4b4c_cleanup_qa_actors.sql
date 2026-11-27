-- TP-FLOW-4B4C exact QA actor cleanup package.
-- Run statement-by-statement in MySQL Workbench on an authorized admin connection.
-- This file contains no credentials and must not be executed by Codex.

SELECT DATABASE() AS target_database, CURRENT_USER() AS executing_account;

-- Required guard: target_database must be exactly r_erp_db_core_0_test.

SET @requester_marker = 'tpflow4b4c_requester';
SET @responsible_marker = 'tpflow4b4c_responsible';

SELECT id, username
FROM usuarios
WHERE username IN (@requester_marker, @responsible_marker);

-- Confirm the two displayed rows are the only intended QA actors before continuing.

START TRANSACTION;

SET @qa_requester_id = (
    SELECT id FROM usuarios WHERE username = @requester_marker LIMIT 1
);
SET @qa_responsible_id = (
    SELECT id FROM usuarios WHERE username = @responsible_marker LIMIT 1
);

SELECT COUNT(*) AS exact_actor_count
FROM usuarios
WHERE id IN (@qa_requester_id, @qa_responsible_id)
  AND username IN (@requester_marker, @responsible_marker);

-- Required guard: exact_actor_count must be 2. Otherwise ROLLBACK and stop.

DELETE ur
FROM usuario_roles ur
INNER JOIN usuarios qa ON qa.id = ur.usuario_id
WHERE qa.id IN (@qa_requester_id, @qa_responsible_id)
  AND qa.username IN (@requester_marker, @responsible_marker);
SET @deleted_role_rows = ROW_COUNT();

DELETE ua
FROM usuario_almacenes ua
INNER JOIN usuarios qa ON qa.id = ua.usuario_id
WHERE qa.id IN (@qa_requester_id, @qa_responsible_id)
  AND qa.username IN (@requester_marker, @responsible_marker);
SET @deleted_warehouse_scope_rows = ROW_COUNT();

DELETE ue
FROM usuario_empresas ue
INNER JOIN usuarios qa ON qa.id = ue.usuario_id
WHERE qa.id IN (@qa_requester_id, @qa_responsible_id)
  AND qa.username IN (@requester_marker, @responsible_marker);
SET @deleted_company_scope_rows = ROW_COUNT();

DELETE u
FROM usuarios u
WHERE u.id IN (@qa_requester_id, @qa_responsible_id)
  AND u.username IN (@requester_marker, @responsible_marker);
SET @deleted_user_rows = ROW_COUNT();

SELECT
    @deleted_role_rows AS deleted_role_rows,
    @deleted_warehouse_scope_rows AS deleted_warehouse_scope_rows,
    @deleted_company_scope_rows AS deleted_company_scope_rows,
    @deleted_user_rows AS deleted_user_rows;

-- Required guards before COMMIT:
-- deleted_user_rows=2; deleted_company_scope_rows=2;
-- deleted_warehouse_scope_rows=2; deleted_role_rows=0.
-- On any mismatch execute ROLLBACK and stop. Otherwise execute COMMIT.

COMMIT;

-- Roles, permissions, companies, warehouses, and real users are never deleted.

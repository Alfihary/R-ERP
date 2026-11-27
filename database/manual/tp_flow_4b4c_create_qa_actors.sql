-- TP-FLOW-4B4C manual QA actor creation package.
-- Run statement-by-statement in MySQL Workbench on an authorized admin connection.
-- This file contains no credentials and must not be executed by Codex.

SELECT DATABASE() AS target_database, CURRENT_USER() AS executing_account;

-- Required guard: target_database must be exactly r_erp_db_core_0_test.
-- Stop without DML if the database is different.

SET @requester_marker = 'tpflow4b4c_requester';
SET @responsible_marker = 'tpflow4b4c_responsible';

SELECT username, id, activo,
       CONCAT(LEFT(email, 4), '***', SUBSTRING_INDEX(email, '@', -1)) AS email_masked
FROM usuarios
WHERE username IN (@requester_marker, @responsible_marker);

SELECT
    SUM(username = @requester_marker) AS requester_existing,
    SUM(username = @responsible_marker) AS responsible_existing
FROM usuarios
WHERE username IN (@requester_marker, @responsible_marker);

-- Required guard: requester_existing=0 and responsible_existing=0.
-- If either marker exists, stop and do not create duplicates.

SELECT
    e.id AS empresa_id,
    a.id AS almacen_id
FROM empresas e
INNER JOIN almacenes a ON a.empresa_id = e.id
WHERE e.activo = 1
  AND e.eliminado_en IS NULL
  AND a.activo = 1
  AND a.eliminado_en IS NULL
ORDER BY e.id, a.id
LIMIT 1;

-- The selected company/warehouse pair is used by both QA actors.
-- No company or warehouse is created or modified.

START TRANSACTION;

INSERT INTO usuarios (
    username,
    email,
    password_hash,
    activo
) VALUES (
    @requester_marker,
    'tpflow4b4c.requester@example.invalid',
    CONCAT('$2y$10$', SUBSTRING(SHA2('TPFLOW4B4C_REQUESTER_NON_LOGIN_FIXTURE', 256), 1, 53)),
    1
);
SET @qa_requester_id = LAST_INSERT_ID();

INSERT INTO usuarios (
    username,
    email,
    password_hash,
    activo
) VALUES (
    @responsible_marker,
    'tpflow4b4c.responsible@example.invalid',
    CONCAT('$2y$10$', SUBSTRING(SHA2('TPFLOW4B4C_RESPONSIBLE_NON_LOGIN_FIXTURE', 256), 1, 53)),
    1
);
SET @qa_responsible_id = LAST_INSERT_ID();

SELECT @qa_requester_id AS requester_id, @qa_responsible_id AS responsible_id;

-- Required guard: both IDs must be nonzero and different.

SET @qa_company_id = (
    SELECT e.id
    FROM empresas e
    INNER JOIN almacenes a ON a.empresa_id = e.id
    WHERE e.activo = 1
      AND e.eliminado_en IS NULL
      AND a.activo = 1
      AND a.eliminado_en IS NULL
    ORDER BY e.id, a.id
    LIMIT 1
);
SET @qa_warehouse_id = (
    SELECT a.id
    FROM almacenes a
    WHERE a.empresa_id = @qa_company_id
      AND a.activo = 1
      AND a.eliminado_en IS NULL
    ORDER BY a.id
    LIMIT 1
);

INSERT INTO usuario_empresas (usuario_id, empresa_id, activo)
VALUES
    (@qa_requester_id, @qa_company_id, 1),
    (@qa_responsible_id, @qa_company_id, 1);
SET @scope_company_rows = ROW_COUNT();

INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo)
VALUES
    (@qa_requester_id, @qa_company_id, @qa_warehouse_id, 1),
    (@qa_responsible_id, @qa_company_id, @qa_warehouse_id, 1);
SET @scope_warehouse_rows = ROW_COUNT();

SELECT
    u.id,
    u.username,
    u.activo,
    CONCAT(LEFT(u.email, 4), '***', SUBSTRING_INDEX(u.email, '@', -1)) AS email_masked,
    ue.empresa_id,
    ua.almacen_id,
    (u.id = @qa_requester_id) AS is_requester,
    (u.id = @qa_responsible_id) AS is_responsible
FROM usuarios u
INNER JOIN usuario_empresas ue ON ue.usuario_id = u.id AND ue.empresa_id = @qa_company_id AND ue.activo = 1
INNER JOIN usuario_almacenes ua ON ua.usuario_id = u.id AND ua.empresa_id = @qa_company_id AND ua.almacen_id = @qa_warehouse_id AND ua.activo = 1
WHERE u.id IN (@qa_requester_id, @qa_responsible_id)
ORDER BY u.id;

SELECT
    @scope_company_rows AS scope_company_rows,
    @scope_warehouse_rows AS scope_warehouse_rows,
    (SELECT COUNT(*) FROM usuario_roles WHERE usuario_id IN (@qa_requester_id, @qa_responsible_id)) AS qa_role_rows;

-- Required guards before COMMIT:
-- requester_id != responsible_id; both active; two distinct synthetic emails;
-- same valid company/warehouse; scope_company_rows=2; scope_warehouse_rows=2;
-- qa_role_rows=0; no ADMIN or other role is created by this package.
-- On any mismatch execute ROLLBACK and stop. Otherwise execute COMMIT.

COMMIT;

-- Provide only @qa_requester_id and @qa_responsible_id to the next runtime phase.
-- Do not provide passwords, hashes, or credentials.

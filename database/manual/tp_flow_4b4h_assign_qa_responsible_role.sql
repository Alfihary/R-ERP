-- TP-FLOW-4B4H manual QA role assignment package.
-- Execute statement-by-statement in MySQL Workbench on an authorized admin connection.
-- This file contains no credentials and must not be executed by Codex.

SELECT DATABASE() AS target_database;
SELECT CURRENT_USER() AS executing_account;

-- Required guard: target_database must be exactly r_erp_db_core_0_test.
-- Stop without DML if the database is different.

SET @qa_requester_id = 4612;
SET @qa_responsible_id = 4613;
SET @resolver_permission_code = 'tickets_productos.resolver';

SELECT u.id, u.username, u.activo, u.eliminado_en,
       u.empresa_id, u.almacen_id
FROM usuarios u
WHERE u.id IN (@qa_requester_id, @qa_responsible_id)
ORDER BY u.id;

SELECT
    SUM(u.id = @qa_requester_id AND u.activo = 1 AND u.eliminado_en IS NULL) AS requester_ready,
    SUM(u.id = @qa_responsible_id AND u.activo = 1 AND u.eliminado_en IS NULL) AS responsible_ready,
    COUNT(DISTINCT CASE WHEN u.id IN (@qa_requester_id, @qa_responsible_id) THEN u.id END) AS distinct_actor_count
FROM usuarios u
WHERE u.id IN (@qa_requester_id, @qa_responsible_id);

-- Required guard: requester_ready=1, responsible_ready=1, distinct_actor_count=2.
-- The requester must not be changed by this package.

SELECT p.id AS resolver_permission_id, p.codigo, p.activo, p.eliminado_en
FROM permisos p
WHERE p.codigo = @resolver_permission_code;

-- Candidate roles are active, non-deleted, non-ADMIN, non-system roles with the
-- exact resolver permission. The first row is the least-privilege candidate by
-- total active permission count, then by stable role id.
SELECT
    r.id AS role_id,
    r.codigo AS role_code,
    r.nombre AS role_name,
    r.es_sistema,
    COUNT(DISTINCT rp_all.permiso_id) AS active_permission_count
FROM roles r
INNER JOIN rol_permisos rp
    ON rp.rol_id = r.id
   AND rp.activo = 1
   AND rp.eliminado_en IS NULL
INNER JOIN permisos p
    ON p.id = rp.permiso_id
   AND p.codigo = @resolver_permission_code
   AND p.activo = 1
   AND p.eliminado_en IS NULL
LEFT JOIN rol_permisos rp_all
    ON rp_all.rol_id = r.id
   AND rp_all.activo = 1
   AND rp_all.eliminado_en IS NULL
WHERE r.activo = 1
  AND r.eliminado_en IS NULL
  AND r.es_sistema = 0
  AND UPPER(r.codigo) <> 'ADMIN'
  AND UPPER(r.nombre) NOT LIKE '%ADMIN%'
GROUP BY r.id, r.codigo, r.nombre, r.es_sistema
ORDER BY active_permission_count, r.id;

-- Required guard: choose exactly one displayed candidate. Never use ADMIN or
-- an equivalent system/bypass role. The assignment below selects the least-
-- privilege candidate deterministically from the displayed candidate set.
SET @selected_role_id = NULL;
SELECT candidate.role_id
INTO @selected_role_id
FROM (
    SELECT r.id AS role_id, COUNT(DISTINCT rp_all.permiso_id) AS permission_count
    FROM roles r
    INNER JOIN rol_permisos rp
        ON rp.rol_id = r.id
       AND rp.activo = 1
       AND rp.eliminado_en IS NULL
    INNER JOIN permisos p
        ON p.id = rp.permiso_id
       AND p.codigo = @resolver_permission_code
       AND p.activo = 1
       AND p.eliminado_en IS NULL
    LEFT JOIN rol_permisos rp_all
        ON rp_all.rol_id = r.id
       AND rp_all.activo = 1
       AND rp_all.eliminado_en IS NULL
    WHERE r.activo = 1
      AND r.eliminado_en IS NULL
      AND r.es_sistema = 0
      AND UPPER(r.codigo) <> 'ADMIN'
      AND UPPER(r.nombre) NOT LIKE '%ADMIN%'
    GROUP BY r.id
    ORDER BY permission_count, r.id
    LIMIT 1
) AS candidate;

SELECT
    r.id AS selected_role_id,
    r.codigo AS selected_role_code,
    r.nombre AS selected_role_name,
    r.es_sistema,
    COUNT(DISTINCT rp.permiso_id) AS selected_role_permission_count
FROM roles r
INNER JOIN rol_permisos rp
    ON rp.rol_id = r.id
   AND rp.activo = 1
   AND rp.eliminado_en IS NULL
INNER JOIN permisos p
    ON p.id = rp.permiso_id
   AND p.codigo = @resolver_permission_code
   AND p.activo = 1
   AND p.eliminado_en IS NULL
WHERE r.id = @selected_role_id
  AND r.activo = 1
  AND r.eliminado_en IS NULL
  AND r.es_sistema = 0
  AND UPPER(r.codigo) <> 'ADMIN'
  AND UPPER(r.nombre) NOT LIKE '%ADMIN%'
GROUP BY r.id, r.codigo, r.nombre, r.es_sistema;

-- Stop if @selected_role_id is NULL or the validation SELECT returns no row.
-- Do not continue to the transaction until the role and permission checks pass.

SELECT ur.usuario_id, ur.rol_id, ur.activo, ur.eliminado_en,
       ur.eliminado_por, ur.actualizado_por
FROM usuario_roles ur
WHERE ur.usuario_id = @qa_responsible_id
  AND ur.rol_id = @selected_role_id;

SELECT
    CASE WHEN EXISTS (
        SELECT 1 FROM usuario_roles
        WHERE usuario_id = @qa_responsible_id
          AND rol_id = @selected_role_id
          AND activo = 1
          AND eliminado_en IS NULL
    ) THEN 'ACTIVE_ROW_EXISTS' ELSE 'NO_ACTIVE_ROW' END AS responsible_role_state,
    CASE WHEN EXISTS (
        SELECT 1 FROM usuario_roles
        WHERE usuario_id = @qa_requester_id
          AND rol_id = @selected_role_id
          AND activo = 1
          AND eliminado_en IS NULL
    ) THEN 'UNEXPECTED_REQUESTER_ROLE' ELSE 'REQUESTER_UNASSIGNED' END AS requester_role_state;

-- If the responsible row is absent, use INSERT_NEW below.
-- If a row exists but is inactive/soft-deleted, use REACTIVATE_EXISTING below.
-- If an active row exists, stop: no DML is required.

SELECT u.id AS audit_actor_id, u.username AS audit_actor_username
FROM usuarios u
INNER JOIN usuario_roles ur
    ON ur.usuario_id = u.id
   AND ur.activo = 1
   AND ur.eliminado_en IS NULL
INNER JOIN roles admin_role
    ON admin_role.id = ur.rol_id
   AND admin_role.codigo = 'ADMIN'
   AND admin_role.activo = 1
   AND admin_role.eliminado_en IS NULL
WHERE u.activo = 1
  AND u.eliminado_en IS NULL
ORDER BY u.id
LIMIT 1;

-- Set @audit_actor_id to the verified administrative actor id shown above.
-- Do not use a made-up id. The actor is audit metadata only; no ADMIN role is
-- assigned to either QA actor.
SET @audit_actor_id = NULL;

START TRANSACTION;

-- INSERT_NEW branch: run only when the exact PK row does not exist.
-- SET @setup_strategy = 'INSERT_NEW';
-- INSERT INTO usuario_roles (usuario_id, rol_id, activo, creado_por, actualizado_por)
-- VALUES (@qa_responsible_id, @selected_role_id, 1, @audit_actor_id, @audit_actor_id);
-- SET @affected_role_rows = ROW_COUNT();

-- REACTIVATE_EXISTING branch: run only when the exact PK row exists but is
-- inactive/soft-deleted. Preserve the precheck values for the future cleanup.
-- SET @setup_strategy = 'REACTIVATE_EXISTING';
-- UPDATE usuario_roles
-- SET activo = 1,
--     eliminado_en = NULL,
--     eliminado_por = NULL,
--     actualizado_por = @audit_actor_id
-- WHERE usuario_id = @qa_responsible_id
--   AND rol_id = @selected_role_id
--   AND activo = 0;
-- SET @affected_role_rows = ROW_COUNT();

-- Required guard: exactly one branch must have been executed and
-- @affected_role_rows must equal 1. Otherwise execute ROLLBACK and stop.

SELECT ur.usuario_id, ur.rol_id, ur.activo, ur.eliminado_en,
       r.codigo AS role_code, p.codigo AS permission_code
FROM usuario_roles ur
INNER JOIN roles r ON r.id = ur.rol_id
INNER JOIN rol_permisos rp
    ON rp.rol_id = r.id
   AND rp.activo = 1
   AND rp.eliminado_en IS NULL
INNER JOIN permisos p
    ON p.id = rp.permiso_id
   AND p.codigo = @resolver_permission_code
   AND p.activo = 1
   AND p.eliminado_en IS NULL
WHERE ur.usuario_id = @qa_responsible_id
  AND ur.rol_id = @selected_role_id
  AND ur.activo = 1
  AND ur.eliminado_en IS NULL;

-- Required pre-COMMIT guard: one active relation for 4613, zero active
-- relation for 4612, and selected role is non-ADMIN with exact permission.
-- Execute COMMIT only after all guards pass.
-- COMMIT;

-- If any guard fails, execute ROLLBACK and stop.
-- ROLLBACK;

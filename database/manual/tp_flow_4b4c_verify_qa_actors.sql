-- TP-FLOW-4B4C SELECT-only QA actor verification.
-- This file contains no DML and no credentials.

SELECT DATABASE() AS target_database, CURRENT_USER() AS executing_account;

-- Required guard: target_database must be exactly r_erp_db_core_0_test.

SET @requester_marker = 'tpflow4b4c_requester';
SET @responsible_marker = 'tpflow4b4c_responsible';

SELECT
    u.id,
    u.username,
    u.activo,
    CONCAT(LEFT(u.email, 4), '***', SUBSTRING_INDEX(u.email, '@', -1)) AS email_masked,
    ue.empresa_id,
    ua.almacen_id,
    (u.username = @requester_marker) AS is_requester,
    (u.username = @responsible_marker) AS is_responsible
FROM usuarios u
LEFT JOIN usuario_empresas ue ON ue.usuario_id = u.id AND ue.activo = 1 AND ue.eliminado_en IS NULL
LEFT JOIN usuario_almacenes ua ON ua.usuario_id = u.id AND ua.activo = 1 AND ua.eliminado_en IS NULL
WHERE u.username IN (@requester_marker, @responsible_marker)
  AND u.eliminado_en IS NULL
ORDER BY u.id;

SELECT
    COUNT(*) AS qa_actor_count,
    COUNT(DISTINCT id) AS distinct_id_count,
    COUNT(DISTINCT email) AS distinct_email_count,
    MIN(activo) AS all_active
FROM usuarios
WHERE username IN (@requester_marker, @responsible_marker)
  AND eliminado_en IS NULL;

SELECT
    u.id AS qa_actor_id,
    r.codigo AS role_code
FROM usuarios u
INNER JOIN usuario_roles ur ON ur.usuario_id = u.id AND ur.activo = 1 AND ur.eliminado_en IS NULL
INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.eliminado_en IS NULL
WHERE u.username IN (@requester_marker, @responsible_marker)
ORDER BY u.id, r.codigo;

-- Expected: qa_actor_count=2, distinct_id_count=2, distinct_email_count=2,
-- all_active=1, and no role rows unless a separately approved HTTP harness requires them.

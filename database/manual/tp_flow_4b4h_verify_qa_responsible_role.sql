-- TP-FLOW-4B4H SELECT-only verification package.
-- Execute on an authorized connection after the manual assignment has committed.

SELECT DATABASE() AS target_database;
SELECT CURRENT_USER() AS executing_account;

SET @qa_requester_id = 4612;
SET @qa_responsible_id = 4613;
SET @resolver_permission_code = 'tickets_productos.resolver';
SET @selected_role_id = NULL;

-- Set @selected_role_id to the exact role id recorded by the assignment script.
-- Required target database: r_erp_db_core_0_test.

SELECT u.id, u.username, u.activo, u.eliminado_en,
       u.empresa_id, u.almacen_id
FROM usuarios u
WHERE u.id IN (@qa_requester_id, @qa_responsible_id)
ORDER BY u.id;

SELECT r.id AS role_id, r.codigo, r.nombre, r.activo, r.eliminado_en,
       r.es_sistema, p.id AS permiso_id, p.codigo AS permiso_codigo,
       p.activo AS permiso_activo, p.eliminado_en AS permiso_eliminado
FROM roles r
INNER JOIN rol_permisos rp
    ON rp.rol_id = r.id AND rp.activo = 1 AND rp.eliminado_en IS NULL
INNER JOIN permisos p
    ON p.id = rp.permiso_id AND p.codigo = @resolver_permission_code
   AND p.activo = 1 AND p.eliminado_en IS NULL
WHERE r.id = @selected_role_id
  AND r.activo = 1
  AND r.eliminado_en IS NULL;

SELECT ur.usuario_id, ur.rol_id, ur.activo, ur.eliminado_en,
       u.username, r.codigo AS role_code
FROM usuario_roles ur
INNER JOIN usuarios u ON u.id = ur.usuario_id
INNER JOIN roles r ON r.id = ur.rol_id
WHERE ur.rol_id = @selected_role_id
  AND ur.usuario_id IN (@qa_requester_id, @qa_responsible_id)
ORDER BY ur.usuario_id;

-- Canonical responsible lookup for ticket scope empresa_id=1, almacen_id=1,
-- excluding requester actor 4612. This mirrors ResponsibleRecipientRepository.
SELECT DISTINCT u.id AS responsible_user_id,
       u.username,
       CONCAT(LEFT(u.email, 2), '***', SUBSTRING_INDEX(u.email, '@', -1)) AS email_masked
FROM usuarios u
INNER JOIN usuario_empresas ue
    ON ue.usuario_id = u.id AND ue.empresa_id = 1
   AND ue.activo = 1 AND ue.eliminado_en IS NULL
INNER JOIN usuario_almacenes ua
    ON ua.usuario_id = u.id AND ua.empresa_id = 1 AND ua.almacen_id = 1
   AND ua.activo = 1 AND ua.eliminado_en IS NULL
INNER JOIN usuario_roles ur
    ON ur.usuario_id = u.id AND ur.activo = 1 AND ur.eliminado_en IS NULL
INNER JOIN roles r
    ON r.id = ur.rol_id AND r.activo = 1 AND r.eliminado_en IS NULL
INNER JOIN rol_permisos rp
    ON rp.rol_id = r.id AND rp.activo = 1 AND rp.eliminado_en IS NULL
INNER JOIN permisos p
    ON p.id = rp.permiso_id AND p.codigo = @resolver_permission_code
   AND p.activo = 1 AND p.eliminado_en IS NULL
INNER JOIN almacenes a
    ON a.id = ua.almacen_id AND a.empresa_id = ua.empresa_id
   AND a.activo = 1 AND a.eliminado_en IS NULL
WHERE u.id <> @qa_requester_id
  AND u.activo = 1
  AND u.eliminado_en IS NULL
  AND u.id = @qa_responsible_id
ORDER BY u.id;

-- Expected: canonical lookup returns 4613 and does not return 4612.

---
name: erp-db-phase-guardian
description: Usar cuando la tarea involucre base de datos, migraciones, seeds, tablas, índices, llaves foráneas, datos iniciales o DB-TEST del ERP. Obliga a construir la base de datos por fases revisables y autorizadas.
---

# ERP DB Phase Guardian

## Misión

Controlar toda modificación de base de datos del ERP para que se haga por fases, con migraciones, seeds, pruebas SQL y criterios de aceptación.

## Reglas obligatorias

- No crear toda la base de datos de golpe.
- No modificar BD sin fase definida.
- No avanzar de fase sin autorización.
- Todo cambio de BD debe tener migración.
- Todo dato inicial debe tener seed.
- Toda fase debe tener DB-TEST.
- No borrar migraciones aplicadas sin plan de rollback.
- No usar cascadas peligrosas en tablas críticas.
- No guardar roles, empresas ni almacenes dentro de usuario_perfiles.
- No usar borrado físico para datos críticos; usar estados lógicos.
- Usar InnoDB.
- Usar charset/collation consistente.
- Usar llaves primarias.
- Usar llaves foráneas.
- Usar índices adecuados.
- Usar campos de auditoría.

## Campos de auditoría estándar

Cuando aplique, usar:

- creado_en
- actualizado_en
- creado_por
- actualizado_por

Para borrado lógico, usar:

- activo
- eliminado_en
- eliminado_por

## Fases de BD recomendadas

BD-CORE-0:
- usuarios
- usuario_perfiles
- roles
- permisos
- usuario_roles
- rol_permisos
- auditoria_logs

BD-SCOPE-1:
- empresas
- almacenes
- usuario_empresas
- usuario_almacenes

BD-SECURITY-2:
- intentos_login
- password_resets si aplica

BD-FOLIOS-3:
- series_folios

BD-THEMES-4:
- ui_temas
- ui_tema_tokens si aplica

BD-CATALOGOS-5:
- productos
- unidades_medida
- marcas
- lineas_producto
- clasificaciones_producto
- monedas
- impuestos
- unidades_sat
- claves_sat

BD-INVENTARIO-6:
- existencias
- inventario_movimientos
- conceptos_movimiento_inventario

BD-TICKETS-7:
- tickets
- ticket_partidas
- ticket_comentarios
- ticket_archivos

BD-MAIL-8:
- mail_templates
- mail_queue
- mail_logs
- notification_rules
- notification_events si aplica

## Entregable obligatorio de cada fase

Para cada fase entregar:

1. Objetivo.
2. Tablas involucradas.
3. Migración propuesta.
4. Seeds si aplican.
5. Relaciones.
6. Llaves primarias.
7. Llaves foráneas.
8. Índices.
9. Restricciones.
10. Campos de auditoría.
11. Reglas de negocio.
12. DB-TEST.
13. SELECT de verificación.
14. INSERT válido.
15. INSERT inválido que debe fallar.
16. Resultado esperado.
17. Cómo interpretar errores.
18. Criterios de aceptación.
19. Riesgo de rollback.
20. Qué NO hacer.

## DB-TEST mínimo

Cada DB-TEST debe validar:

- Tablas existentes.
- Motor InnoDB.
- Charset/collation.
- Índices.
- Llaves foráneas.
- Datos seed.
- Duplicados.
- Relaciones inválidas que deben fallar.
- Usuarios sin perfil si aplica.
- Permisos duplicados si aplica.
- Roles duplicados si aplica.

## Antes de cambiar BD

Indicar:

- Fase.
- Migraciones nuevas.
- Migraciones modificadas.
- Seeds nuevos.
- Seeds modificados.
- Pruebas.
- Riesgo de rollback.
- Si requiere respaldo de BD.

## Prohibido

- Crear tablas operativas sin empresa_id o almacen_id cuando aplique.
- Crear permisos sin seed.
- Crear tabla sin índices mínimos.
- Crear tabla crítica con cascadas peligrosas.
- Usar MyISAM.
- Guardar secretos en BD.
- Saltar DB-TEST.
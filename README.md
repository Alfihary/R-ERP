# SoporteGR ERP

ERP modular para PHP 8.x y MySQL, diseñado para operar múltiples empresas y
almacenes en una sola base de datos, con permisos por acción, alcance operativo,
auditoría y folios.

## Estado actual

Fase autorizada: `GIT-0 + DOCS-0 + ARCH-0`.

Este repositorio contiene únicamente la base documental, la higiene inicial de
Git y el árbol arquitectónico vacío. Todavía no existe aplicación ejecutable,
entry point público, configuración funcional, migraciones, seeds ni módulos de
negocio.

## Decisiones base

- PHP renderizará las vistas principales mediante una arquitectura MVC modular
  propia.
- No se usarán Laravel, microservicios, SPA, React, Vue, Angular, jQuery ni
  Bootstrap como framework visual.
- PDO y prepared statements serán obligatorios cuando se apruebe la capa de
  persistencia.
- La autorización efectiva combinará permiso por acción y alcance por empresa y
  almacén.
- Tailwind CSS se compilará localmente; AwardSpace recibirá únicamente el CSS
  final.
- JavaScript será vanilla, modular y progresivo.
- Los archivos privados vivirán en `storage/`, nunca en `public/`.
- Mail y Notifications serán subsistemas centrales; ningún módulo enviará SMTP
  directamente.
- La base de datos se construirá y aprobará por fases con migración, seed cuando
  aplique y DB-TEST.

## Documentación

- `docs/arquitectura.md`: capas, dependencias y estructura.
- `docs/seguridad.md`: controles y modelo de autorización.
- `docs/convenciones.md`: reglas de nombres, código, rutas y Git.
- `docs/base-datos.md`: modelo conceptual y gobierno por fases.
- `docs/fases.md`: secuencia de construcción y puertas de aprobación.
- `docs/deploy-awardspace.md`: preparación, exclusiones y rollback.
- `docs/mail-notifications.md`: diseño conceptual del subsistema.

## Flujo de trabajo

1. Identificar la fase.
2. Presentar alcance, archivos, pruebas y riesgo.
3. Obtener autorización expresa.
4. Implementar solo esa fase.
5. Ejecutar verificaciones.
6. Revisar diff y criterios de aceptación.
7. Cerrar la fase antes de solicitar la siguiente.

## Reglas obligatorias

- No iniciar módulos operativos antes de aprobar arquitectura, seguridad,
  permisos, alcance, base de datos inicial y DB-TEST.
- No versionar `.env`, dependencias locales, logs, uploads, dumps ni respaldos.
- No guardar datos privados o secretos en `public/`.
- No avanzar de fase por inferencia.
- Mantener `main` estable cuando exista el primer commit aprobado.

## Pendiente de aprobar

- `CONFIG-0`: bootstrap y configuración real.
- `SECURITY-0`: primitivas de seguridad y middlewares.
- `DB-CORE-0`: primera migración y sus seeds.
- `DB-TEST-CORE`: ejecución de pruebas sobre una base exclusiva.
- Estrategia concreta de Composer y dependencias PHP.
- Configuración de Tailwind y scripts locales.

## Fuera de alcance de esta fase

- Código PHP operativo y `public/index.php`.
- SQL ejecutable, migraciones y seeds.
- Login, sesiones, CSRF, permisos y servicios funcionales.
- Productos, inventario, tickets, compras, ventas y reportes.
- Integración SMTP, cron, despliegue o cambios en producción.

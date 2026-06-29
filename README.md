# SoporteGR ERP

ERP modular para PHP 8.x y MySQL, diseñado para operar múltiples empresas y
almacenes en una sola base de datos, con permisos por acción, alcance operativo,
auditoría y folios.

## Estado actual

Fase en revisión: `CONFIG-0`.

El repositorio contiene un arranque técnico mínimo con entry point público,
autoload `App\`, configuración por entorno y rutas `GET /` y `GET /health`.
Todavía no existen seguridad, conexión de base de datos, migraciones, seeds ni
módulos de negocio.

## Arranque local

Requiere PHP 8.x. Desde la raíz del repositorio:

```powershell
php -S 127.0.0.1:8000 -t public
```

Después se pueden consultar:

- `http://127.0.0.1:8000/`
- `http://127.0.0.1:8000/health`

La aplicación usa valores seguros documentados en `.env.example` cuando no
existe `.env`. Para configuración local se puede copiar esa plantilla a `.env`;
el archivo real permanece ignorado por Git.

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

- Cierre de `CONFIG-0`: bootstrap y configuración mínima.
- `SECURITY-0`: primitivas de seguridad y middlewares.
- `DB-CORE-0`: primera migración y sus seeds.
- `DB-TEST-CORE`: ejecución de pruebas sobre una base exclusiva.
- Estrategia concreta de Composer y dependencias PHP.
- Configuración de Tailwind y scripts locales.

## Fuera de alcance de esta fase

- SQL ejecutable, migraciones y seeds.
- Login, sesiones, CSRF, permisos y servicios funcionales.
- Productos, inventario, tickets, compras, ventas y reportes.
- Integración SMTP, cron, despliegue o cambios en producción.

---
name: awardspace-deploy-guard
description: Usar antes de preparar o ejecutar deploy del ERP en AwardSpace. Revisa archivos permitidos, .env, storage, public, CSS compilado, JS final, migraciones, respaldo, checklist, rollback y seguridad de producción.
---

# AwardSpace Deploy Guard

## Misión

Proteger el deploy del ERP en hosting compartido AwardSpace.

## Reglas obligatorias

- No compilar nada en AwardSpace.
- No ejecutar Node.js en AwardSpace.
- No ejecutar npm, npx, Vite ni Webpack en AwardSpace.
- Tailwind se compila localmente.
- AwardSpace recibe solo CSS final.
- No subir node_modules.
- No subir .env por Git.
- No subir logs.
- No subir uploads privados.
- No subir dumps SQL.
- No subir respaldos zip, bak o sql.
- APP_DEBUG debe estar apagado en producción.
- storage debe estar protegido.
- public debe ser el único punto público.
- app, config, database y storage no deben ser navegables.

## Archivos que sí pueden subirse

- app/
- config/
- routes/
- database/migrations/
- database/seeds/
- docs/ si se decide subir documentación no sensible
- public/index.php
- public/css/app.css
- public/css/core/
- public/css/modules/
- public/css/themes/
- public/js/
- public/images/
- vendor/ solo si el proyecto depende de Composer y no se instala en servidor
- .htaccess necesarios
- storage/.gitkeep o estructura vacía protegida

## Archivos que NO deben subirse

- .env local
- node_modules/
- resources/node build caches
- logs
- uploads privados
- dumps SQL
- respaldos .zip
- respaldos .bak
- archivos temporales
- pruebas con datos reales
- credenciales SMTP
- claves API

## Checklist antes de deploy

1. Confirmar rama estable.
2. Confirmar commit aprobado.
3. Confirmar DB-TEST local aprobado.
4. Confirmar CSS final compilado.
5. Confirmar JS final en public/js.
6. Confirmar .env de producción preparado manualmente.
7. Confirmar APP_DEBUG=false.
8. Confirmar respaldo de archivos.
9. Confirmar respaldo de BD.
10. Confirmar migraciones a ejecutar.
11. Confirmar plan de rollback.
12. Confirmar storage protegido.
13. Confirmar rutas críticas.
14. Confirmar permisos de carpetas.
15. Confirmar que no hay dumps, logs ni uploads en el paquete.

## Checklist después de deploy

1. Probar /login.
2. Probar /dashboard.
3. Probar logout.
4. Probar protección de ruta privada.
5. Probar que APP_DEBUG no muestra stack traces.
6. Probar carga de CSS.
7. Probar carga de JS.
8. Probar una consulta simple de BD.
9. Probar storage protegido.
10. Probar que archivos privados no son públicos.
11. Probar DB-TEST si aplica.
12. Probar permisos principales.

## Resultado esperado

Cuando se invoque esta skill, entregar:

- Diagnóstico de deploy.
- Archivos a subir.
- Archivos prohibidos.
- Migraciones pendientes.
- Riesgos.
- Checklist.
- Plan de rollback.
- Veredicto final.
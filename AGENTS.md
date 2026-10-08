# AGENTS.md — Reglas del proyecto ERP SoporteGR

## Contexto del proyecto

Este repositorio contiene un ERP PHP 8.x/MySQL modular, con arquitectura MVC propia, una sola base de datos, múltiples empresas, múltiples almacenes, permisos por acción, alcance operativo por empresa/almacén, auditoría, folios, Tailwind CSS compilado localmente, JavaScript vanilla modular y deploy en AwardSpace.

## Reglas obligatorias

- No usar Laravel.
- No usar microservicios.
- No construir una SPA al inicio.
- No usar React, Vue, Angular ni jQuery al inicio.
- No usar Bootstrap como framework visual del ERP.
- No compilar Tailwind en AwardSpace.
- No ejecutar Node.js, npm, npx, Vite ni Webpack en AwardSpace.
- No subir `.env`, `node_modules`, logs, uploads, dumps SQL ni respaldos.
- No guardar archivos privados en `public/`.
- No mostrar errores sensibles en producción.
- No construir módulos operativos antes de aprobar arquitectura, seguridad, permisos, alcance, base de datos inicial y DB-TEST.

## Forma obligatoria de trabajo

Antes de modificar archivos, Codex debe indicar:

1. Fase detectada.
2. Rama Git sugerida.
3. Objetivo.
4. Archivos que tocará.
5. Migraciones afectadas.
6. Seeds afectados.
7. Servicios involucrados.
8. Pruebas mínimas.
9. DB-TEST si aplica.
10. Riesgo de rollback.
11. Qué NO hará.

Después de modificar archivos, Codex debe entregar:

1. Resumen de cambios.
2. Archivos modificados.
3. Migraciones creadas o modificadas.
4. Seeds creados o modificados.
5. Pruebas realizadas.
6. Resultado esperado.
7. Comando sugerido de `git status`.
8. Comando sugerido de `git diff`.
9. Mensaje de commit recomendado.
10. Checklist antes de deploy.
11. Advertencias.

## Seguridad

- Toda ruta privada debe pasar por AuthMiddleware.
- Toda acción sensible debe pasar por PermissionMiddleware.
- Toda acción con empresa o almacén debe validar UserScopeService.
- Todo POST, PUT, PATCH o DELETE debe validar CSRF.
- Toda consulta SQL debe usar PDO y prepared statements.
- Ninguna vista debe imprimir datos del usuario sin escape.
- Las descargas privadas deben validar permiso y alcance.
- Las exportaciones deben tener permiso propio.
- Las acciones críticas deben auditarse.

## Base de datos

La base de datos debe construirse por fases.

Cada fase debe incluir:

- Migración.
- Seed si aplica.
- DB-TEST.
- SELECT de verificación.
- INSERT válido.
- INSERT inválido que debe fallar.
- Resultado esperado.
- Criterios de aceptación.
- Riesgo de rollback.

No avanzar de fase sin autorización.

## Deploy AwardSpace

AwardSpace solo debe recibir:

- PHP.
- CSS final.
- JS final.
- Assets públicos.
- Storage protegido.

No subir:

- `.env`.
- `node_modules`.
- Logs.
- Uploads privados.
- Dumps SQL.
- Respaldos.
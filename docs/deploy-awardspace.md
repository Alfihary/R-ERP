# Estrategia de deploy en AwardSpace

## Estado del documento

- Fase: `DOCS-0`.
- Estado: política de preparación; no es un procedimiento autorizado.
- No se ha verificado todavía la cuenta, versión de PHP/MySQL, cron, HTTPS,
  estructura de directorios ni límites del plan real.
- Esta fase no prepara ni ejecuta un deploy.

## Objetivo

Definir un despliegue reproducible y reversible para hosting compartido, sin
compiladores o procesos permanentes en el servidor.

## Principio de artefacto

Todo lo que requiera compilación o instalación se prepara localmente. El
servidor recibe artefactos finales:

- PHP aprobado.
- Dependencias PHP necesarias, si Composer no puede ejecutarse en servidor.
- CSS final.
- JavaScript final.
- Imágenes públicas.
- Estructura privada protegida.
- Migraciones y seeds aprobados que deban ejecutarse mediante procedimiento
  controlado.

AwardSpace no ejecutará Node.js, npm, npx, Vite, Webpack ni la compilación de
Tailwind.

## Topología pública

La opción preferida es configurar `public/` como raíz web. Así, `app/`,
`config/`, `database/`, `resources/` y `storage/` quedan fuera del alcance HTTP.

Si el plan real no permite cambiar la raíz pública, `DEPLOY-0` deberá proponer y
probar una disposición alternativa con reglas del servidor. No se moverán
secretos o archivos privados a `public/` para resolver una limitación del
hosting.

## Contenido permitido en un paquete futuro

Sujeto a la fase y al manifiesto aprobado:

```text
app/
config/
routes/
database/migrations/
database/seeds/
public/
vendor/                  si es necesario
storage/                 solo estructura protegida y vacía
```

`docs/` solo se incluirá si no contiene información operativa sensible y existe
una razón concreta. No debe quedar navegable públicamente.

## Contenido prohibido

- `.env` local.
- `node_modules/`.
- Cachés de npm o del build.
- Logs.
- Uploads privados.
- Previews de correo.
- Dumps SQL.
- Respaldos `.zip`, `.bak`, `.sql`, `.dump` o equivalentes.
- Datos de prueba reales.
- Credenciales SMTP, BD o APIs.
- Archivos temporales.
- Configuración del IDE.
- Scripts de diagnóstico no aprobados.

## Tailwind y assets

Flujo futuro:

1. Editar fuentes bajo `resources/css`.
2. Compilar en entorno local controlado.
3. Producir `public/css/app.css`.
4. Verificar que las vistas necesarias fueron consideradas por el compilador.
5. Revisar tamaño y ausencia de sourcemaps no autorizados.
6. Probar localmente el CSS final.
7. Incluir el artefacto final en el paquete.

`node_modules/` y fuentes de build no son necesarios en AwardSpace. Los scripts
locales se definirán en una fase posterior; `package.json` no se modifica en
`DOCS-0`.

## Configuración de producción

La configuración se preparará manualmente fuera de Git:

- `APP_ENV=production`.
- `APP_DEBUG=false`.
- URL HTTPS correcta.
- Zona horaria aprobada.
- Credenciales de BD con privilegios mínimos.
- Cookie segura.
- SMTP y remitente.
- Tokens internos rotados y aleatorios si existen endpoints protegidos.

`.env.example` no debe utilizarse como archivo productivo sin completar,
proteger y revisar cada valor.

## Storage

- Debe quedar fuera de la raíz pública cuando sea posible.
- Sus permisos deben permitir únicamente lo necesario al proceso PHP.
- Uploads, logs, cache y temporales no se copian desde desarrollo.
- Las descargas privadas pasan por la aplicación.
- Se validará que no exista listado de directorios.
- Se probará acceso HTTP directo a rutas privadas.
- El deploy no reemplaza contenido privado existente sin respaldo.

## Migraciones

Antes de cualquier deploy con cambios de BD:

1. Identificar migraciones exactas.
2. Confirmar la fase aprobada.
3. Ejecutar DB-TEST local.
4. Obtener respaldo de producción.
5. Evaluar compatibilidad hacia atrás.
6. Definir orden entre archivos y esquema.
7. Definir rollback o corrección hacia adelante.
8. Registrar resultado.

No se ejecutan todas las migraciones indiscriminadamente ni se importa un dump
completo como mecanismo de deploy.

## Checklist previo

- [ ] Rama estable confirmada.
- [ ] Árbol de trabajo limpio.
- [ ] Commit y fase aprobados.
- [ ] Cambios revisados.
- [ ] Pruebas locales aprobadas.
- [ ] DB-TEST aprobado si aplica.
- [ ] Versión PHP y extensiones compatibles.
- [ ] Versión MySQL compatible.
- [ ] CSS final compilado y probado.
- [ ] JavaScript final probado.
- [ ] `.env` productivo preparado fuera de Git.
- [ ] `APP_DEBUG=false`.
- [ ] Paquete sin archivos prohibidos.
- [ ] Respaldo de archivos.
- [ ] Respaldo de BD si aplica.
- [ ] Ventana y responsables confirmados.
- [ ] Plan de rollback escrito.
- [ ] Rutas privadas y storage protegidos.

## Procedimiento futuro de deploy

El procedimiento exacto dependerá de las capacidades verificadas de la cuenta,
pero deberá:

1. Registrar versión actual.
2. Crear respaldos externos al directorio público.
3. Preparar un manifiesto del paquete.
4. Subir a una ubicación controlada.
5. Aplicar archivos de forma predecible.
6. Ejecutar únicamente migraciones autorizadas.
7. Limpiar caché de aplicación si existe.
8. Ejecutar smoke tests.
9. Confirmar logs sin errores críticos.
10. Cerrar o ejecutar rollback.

No se improvisarán cambios manuales directamente sobre archivos productivos sin
que el cambio exista en Git.

## Smoke tests posteriores

- Login carga por HTTPS.
- Ruta privada rechaza usuario anónimo.
- Dashboard carga sin errores sensibles.
- Logout invalida sesión.
- CSS y JavaScript responden correctamente.
- `APP_DEBUG` no expone trazas.
- Conexión de BD funciona.
- Permiso denegado responde de forma segura.
- Alcance impide acceder a recursos ajenos.
- Storage privado no es navegable.
- Un archivo privado no es accesible por URL directa.
- Mail en modo aprobado no expone errores o secretos.
- Logs no contienen credenciales.

Solo se prueban flujos existentes y autorizados.

## Rollback

El plan debe cubrir por separado:

### Archivos

- Conservar versión anterior.
- Restaurar el manifiesto previo.
- Evitar borrar uploads o configuración externa.

### Base de datos

- Restaurar desde respaldo solo con autorización y evaluación de pérdida de
  datos.
- Preferir migración inversa segura o corrección hacia adelante cuando ya hubo
  nuevas operaciones.

### Configuración

- Conservar copia protegida de valores previos.
- Rotar secretos si pudieron exponerse.

El rollback se activa por criterios definidos antes del deploy, no por
improvisación.

## Riesgos

- Raíz pública configurada incorrectamente.
- Versión PHP/MySQL incompatible.
- Permisos de filesystem demasiado amplios o insuficientes.
- CSS compilado incompleto.
- Migración parcialmente aplicada.
- Deploy que sobreescribe contenido privado.
- Error visible por `APP_DEBUG=true`.
- Dependencia faltante en `vendor/`.
- Ausencia de cron para la cola de correo.

Todos deben verificarse durante `DEPLOY-0`.

## Reglas obligatorias

- Compilar y probar localmente.
- Desplegar solo artefactos aprobados.
- Mantener secretos fuera de Git.
- No subir Node, logs, uploads, dumps o respaldos.
- Apagar debug en producción.
- Proteger `storage` y directorios no públicos.
- Respaldar antes de cambios con riesgo.
- Definir rollback y smoke tests.
- No desplegar desde un árbol sucio.
- No ejecutar una migración sin DB-TEST y autorización.

## Pendiente de aprobar

- Plan y capacidades reales de AwardSpace.
- Estructura exacta del document root.
- Versiones de PHP y MySQL.
- Extensiones disponibles.
- Estrategia de transferencia.
- Uso de Composer y contenido de `vendor/`.
- Disponibilidad y frecuencia de cron.
- Reglas `.htaccess` necesarias.
- Procedimiento exacto de backups.
- Política de releases y rollback.

## Fuera de alcance de esta fase

- Conectarse a AwardSpace.
- Crear paquete de deploy.
- Crear `.htaccess`.
- Compilar Tailwind.
- Modificar `package.json`.
- Instalar Composer o dependencias.
- Preparar `.env` real.
- Ejecutar migraciones.
- Hacer backups o cambios de producción.
- Emitir un veredicto de deploy.

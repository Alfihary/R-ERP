# PWA y desbloqueo del dispositivo

## Alcance

PWA instalable con manifiesto, iconos PNG, service worker y pantalla sin conexión.
Las páginas, respuestas API, datos privados, contraseñas y tokens CSRF nunca se
guardan en Cache Storage. Las respuestas PHP tienen `Cache-Control: no-store, private`.
La aplicación y el desbloqueo requieren acceso al servidor.

Después de iniciar sesión con contraseña, el usuario puede activar el desbloqueo
de su sesión con WebAuthn. Confirma su contraseña antes del registro. El navegador
solicita un autenticador de plataforma y verificación de usuario obligatoria.
Puede utilizar huella, rostro o PIN: el sitio no controla cuál y nunca recibe
datos biométricos. No es un inicio de sesión sin contraseña.

La clave pública y el identificador viven solo en la sesión PHP. Al salir o volver
a iniciar sesión se borra la inscripción del servidor. El sistema operativo puede
conservar su propia credencial; esta deja de servir después de finalizar la sesión.
No existen migraciones ni seeds en esta fase. DB-TEST no aplica al esquema.

## Uso

1. Abrir `http://localhost:8000/login` en la PC de desarrollo.
2. Pulsar Instalar aplicación cuando el navegador lo ofrezca, o usar su menú de instalación.
   En iPhone/iPad usar Safari, Compartir, Agregar a inicio. La instalación requiere una acción del usuario.
3. Iniciar sesión con las credenciales del ERP. Requiere la BD configurada y el permiso existente `sistema.app.ver`.
4. Pulsar Activar huella / dispositivo y confirmar la contraseña.
5. Registrar la verificación nativa del dispositivo.
6. Bloquear sesión o esperar cinco minutos sin actividad. Desbloquear para continuar.
7. Cerrar sesión para retirar la protección de esta sesión.

Duración máxima de la protección: ocho horas, incluso si hay actividad. Después
hay que volver a ingresar. El acceso ordinario sin activar esta función conserva
su comportamiento previo. Esta fase no añade limitación de intentos al login general.

## Configuración

Instalar dependencias del archivo lock con `composer install --no-dev --prefer-dist`.
La dependencia de verificación es `lbuchs/webauthn`, fijada en `composer.lock`.
Para producción configurar `APP_WEBAUTHN_ORIGIN=https://dominio-exacto.example`
sin ruta ni barra final. Debe coincidir exactamente en esquema, dominio y puerto.
No se deriva del encabezado Host. En producción queda deshabilitado si falta.
Configurar también APP_ENV=production, APP_DEBUG=false, APP_URL y cookie segura.

Para pruebas locales el origen es `http://localhost:8000`; `127.0.0.1` puede servir
la PWA pero no es el origen configurado para WebAuthn. En un celular localhost
se refiere al propio celular: usar un dominio HTTPS con certificado válido y acceso
al servidor. No usar HTTP sobre una IP LAN para la función biométrica.

## Seguridad

- Rutas privadas con AuthMiddleware; registro y verificación con PermissionMiddleware.
- El bloqueo se aplica en backend a rutas privadas, incluidas escrituras y descargas.
- CSRF en todo POST, origen exacto en operaciones WebAuthn, desafío aleatorio de
  un solo uso con 90 segundos de vigencia y ligado al usuario y operación.
- Verificación criptográfica de firma, RP ID, origen, tipo, presencia y verificación
  de usuario. Control de contador cuando el autenticador lo implementa.
- Máximo de diez solicitudes de opciones/verificación por minuto y sesión.
- Regeneración del ID de sesión al registrar y desbloquear.
- Eventos de activación, bloqueo y verificación enviados al AuditService existente.
  Este servicio conserva su política de auditoría de mejor esfuerzo.
- Consulta de estado no renueva actividad; solo interacción o rutas privadas
  mantienen activa una sesión desbloqueada. Ninguna actividad desbloquea una sesión.
- Cerrar sesión sigue disponible al estar bloqueado. No hay bypass de huella en JS.

El ocultamiento al cambiar de pestaña reduce la exposición visual, pero no borra
información ya descargada por un navegador. El control de acceso efectivo reside
en el servidor. Los permisos y la base de datos deben funcionar para desbloquear.

## Pruebas

`php tests/device-unlock.php` prueba registro sintético con clave EC, firmas
válidas e inválidas, indicadores UP/UV, origen, RP ID, credencial, contador,
caducidad, replay, bloqueo de lecturas/escrituras y limitación de intentos.
En XAMPP puede requerir OPENSSL_CONF apuntando a php/extras/ssl/openssl.cnf
y session.save_path con permisos. No crea cuentas ni modifica la BD.

`node tests/pwa.cjs` verifica manifiesto, dimensiones reales PNG, lista permitida
de caché, bypass de APIs/POST y retorno offline sin copiar contenido privado.

Antes de producción verificar en dispositivos reales Windows Hello, Android e iOS,
cancelación, ausencia de biometría, logout, varias pestañas, caducidad, instalación,
actualización del service worker y bloqueo de endpoints privados. Las pruebas
sintéticas no certifican compatibilidad de hardware ni sustituyen un login real.

## Rollback y entrega

Archivos modificados: vistas auth/login y layouts/app, AuthService,
AuthMiddleware, SecurityHeadersMiddleware, bootstrap/app.php, composer.json y
composer.lock. Archivos nuevos: DeviceUnlockService, SessionLockService,
DeviceUnlockController, vistas auth/unlock y auth/device-controls,
routes/device-unlock.php, config/webauthn.php, public/manifest.webmanifest,
public/sw.js, public/offline.html, public/js/pwa.js, public/js/device-unlock.js,
public/css/modules/pwa.css, public/css/modules/offline.css, cuatro PNG en
public/icons, tests/device-unlock.php, tests/pwa.cjs y este documento.

Resultado de verificación local: 28 comprobaciones de sesión y criptografía
aprobadas; validación PWA y sintaxis PHP/JS aprobadas; manifiesto, iconos, scripts,
service worker y login devuelven HTTP 200. Solicitudes anónimas a estado y
desbloqueo se redirigen a login; POST sin CSRF devuelve 419. Login observado en
navegador sin errores de consola. Composer no reportó avisos de vulnerabilidad
para las dependencias resueltas en esta instalación.

Revisión de seguridad: aprobada con observaciones para pruebas locales. No
certifica producción: esta copia todavía requiere configurar la BD para probar
login, permisos y auditoría con usuarios reales, y validar el autenticador físico.
El registro biométrico y la instalación final requieren intervención del usuario.

Restaurar las vistas, AuthService, AuthMiddleware, SecurityHeadersMiddleware,
bootstrap/app.php y los archivos Composer anteriores. Retirar rutas y archivos
nuevos de esta fase. Desregistrar el service worker y eliminar solo cachés
`gr-public-*` del origen; una PWA ya instalada necesita desinstalación por el usuario.
La reversión retira el bloqueo: debe realizarse en una ventana controlada.

Revisión Git cuando la carpeta sea un repositorio: `git status --short` y
`git diff --stat`, seguido de `git diff`. Commit sugerido:
`feat: add installable PWA and session-scoped WebAuthn unlock`.
Esta copia no tenía `.git` al comenzar; no se crea un repositorio en esta fase.

Checklist previo a deploy: BD y usuario de prueba operativos; origen HTTPS
definitivo; raíz web exclusivamente public; dependencias instaladas; pruebas
anteriores y pruebas físicas aprobadas; permisos y auditoría comprobados; respaldo
de la versión anterior. No subir logs, sesiones, .env local ni archivos de pruebas
al directorio público.

Referencias: https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API
y https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable

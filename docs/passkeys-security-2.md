# AUTH-PASSKEYS-2 — Passkeys multiplataforma

## Comportamiento

El acceso por usuario o correo y contraseña continúa disponible. Después del
primer acceso normal, el usuario puede abrir **Perfil > Dispositivos y
passkeys**, registrar hasta diez credenciales con un nombre descriptivo,
consultar su fecha de alta y último uso, y revocarlas individualmente.

En un navegador compatible, el login ofrece **Ingresar con biometría**. El
autenticador del sistema decide si utiliza huella, rostro, PIN, Windows Hello,
Touch ID o una llave de seguridad. La aplicación nunca recibe ni almacena la
biometría: persiste solamente el identificador, clave pública, RP ID, contador y
metadatos operativos de la credencial WebAuthn.

El botón se presenta cuando el navegador y el servidor soportan WebAuthn. La
aplicación no enumera públicamente si una persona concreta tiene credenciales;
el autenticador solo completa el acceso cuando encuentra una passkey válida.

## Controles de seguridad

- Challenges aleatorios de un solo uso con vencimiento de 90 segundos.
- Verificación estricta de origin, RP ID, `crossOrigin`, presencia y
  verificación del usuario, firma y `signCount`.
- Actualización atómica del contador para rechazar aserciones repetidas o
  concurrentes; los autenticadores sin contador conservan el valor cero.
- Registro y revocación solo dentro de una sesión autenticada, con permiso de
  perfil, CSRF, comprobación del encabezado Origin y confirmación de contraseña.
- La credencial queda vinculada a su usuario y a la versión de su contraseña;
  desactivar la cuenta o cambiar la contraseña invalida sus passkeys.
- Regeneración de sesión al autenticar o registrar. Cookies de sesión
  `HttpOnly`, `SameSite=Lax` y `Secure` en HTTPS/producción.
- No se almacenan secretos de autenticación en `localStorage` ni
  `sessionStorage`.

## Base de datos

La migración `passkeys_security_2_001_add_credential_name` añade `nombre`
`VARCHAR(80) NOT NULL` a `usuario_passkeys`. No se agregan seeds. El rollback
retira únicamente esa columna; debe hacerse después de respaldar si ya existen
nombres que deban conservarse.

Pruebas recomendadas:

```text
php database/passkeys.php db:test --database=gruporefrigerantes_passkeys_test --confirm-database=gruporefrigerantes_passkeys_test
php tests/passkeys.php
php tests/passkeys-repository.php
```

Antes de publicar se debe usar HTTPS con `APP_URL` y
`APP_WEBAUTHN_ORIGIN` iguales al origen público, habilitar la cookie segura y
comprobar al menos un registro, acceso y revocación en cada familia de
dispositivos que vaya a soportarse.

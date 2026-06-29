# AUTH-0 — Autenticación inicial

## Alcance

AUTH-0 incorpora autenticación real por email o `username`, cierre de sesión,
una ruta privada mínima y un comando CLI controlado para crear o rotar las
credenciales del primer administrador.

No incorpora permisos funcionales, recuperación de contraseña, bloqueo por
intentos, dashboard, empresas, almacenes ni módulos operativos.

## Rutas

| Método | Ruta | Protección | Resultado |
|---|---|---|---|
| `GET` | `/login` | pública | Formulario mínimo de acceso |
| `POST` | `/login` | CSRF | Autentica y redirige a `/app` |
| `GET` | `/app` | `AuthMiddleware` | Comprobación mínima de sesión |
| `POST` | `/logout` | autenticación y CSRF | Destruye la sesión y redirige |

El campo de identidad se llama `login`. El servicio normaliza el valor a
minúsculas y consulta `usuarios.email` y `usuarios.username` mediante PDO y
parámetros preparados. Solo autentica registros activos y no eliminados.

Un acceso correcto regenera el identificador de sesión. La sesión conserva
únicamente `user_id`, `username` y `email`; nunca conserva `password_hash`.
Todos los rechazos públicos usan el mensaje genérico
`Credenciales inválidas.`.

## Administrador inicial

El administrador se crea exclusivamente desde CLI y contra una base ya
existente. El comando exige que `APP_DB_NAME`, `--database` y
`--confirm-database` coincidan, y rechaza `APP_ENV=production`.

Configurar solo en el `.env` local:

```dotenv
APP_INITIAL_ADMIN_USERNAME=jesus.g
APP_INITIAL_ADMIN_EMAIL=
APP_INITIAL_ADMIN_PASSWORD=
```

La contraseña temporal debe tener al menos 12 caracteres. No se debe copiar a
`.env.example`, documentación, consola, logs ni control de versiones.

Creación:

```powershell
php database/auth.php create-initial-admin --database=<db-test> --confirm-database=<db-test>
```

El proceso:

1. valida username, email y longitud mínima de contraseña;
2. confirma que el rol estructural `ADMIN` esté activo;
3. impide crear el administrador si ya existen otros usuarios;
4. crea usuario y asignación de rol dentro de una transacción;
5. devuelve únicamente estado e ID, nunca contraseña ni hash;
6. es idempotente para el mismo usuario ya asociado a `ADMIN`.

Rotación controlada:

```powershell
php database/auth.php rotate-initial-admin-password --database=<db-test> --confirm-database=<db-test>
```

Antes de rotar, reemplazar localmente
`APP_INITIAL_ADMIN_PASSWORD` por una contraseña temporal nueva. Después de
probar el acceso, retirar esa variable del `.env` local o dejarla vacía.

## Pruebas de aceptación

- Regresión: `/` responde 200, `/health` responde 200 con
  `{"status":"ok"}` y una ruta inexistente responde 404.
- `/login` responde 200.
- Login válido por email y por username redirige a `/app`.
- Contraseña incorrecta y usuario inexistente producen el mismo mensaje.
- `POST /login` sin CSRF responde 419.
- `/app` sin sesión redirige a `/login`; con sesión responde 200.
- `POST /logout` con CSRF destruye la sesión.
- El rol `ADMIN` persiste, el usuario inicial queda asociado una sola vez y no
  se crean permisos funcionales.

## Rollback

El código de AUTH-0 se revierte retirando sus rutas, servicios, repositorios,
middleware, vistas y configuración. La eliminación del administrador inicial
no es automática: debe hacerse únicamente mediante una operación de base de
datos revisada, porque también existe una relación en `usuario_roles`.

Riesgo de rollback: medio. Retirar el código bloquea el acceso privado, pero no
modifica el esquema aprobado en DB-CORE-0.

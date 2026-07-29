# PERFIL-UI-1

## Objetivo

PERFIL-UI-1 agrega una interfaz privada para que el usuario autenticado consulte y mantenga su propio perfil operativo.

La fase usa el servicio cerrado en `PERFIL-SERVICE-1`; la vista no escribe directo en base de datos.

## Rutas privadas

- `GET /perfil`: muestra el perfil propio, cuenta de sesión y metadatos de foto activa.
- `POST /perfil/actualizar`: actualiza únicamente campos permitidos del perfil.
- `GET /perfil/password`: muestra el formulario de cambio de contraseña.
- `POST /perfil/password`: cambia la contraseña propia con validación de contraseña actual.
- `POST /perfil/foto`: carga una foto privada de perfil desde `PERFIL-FOTO-UPLOAD-1`.
- `POST /perfil/foto/eliminar`: elimina lógicamente la foto activa.

Todas las rutas pasan por autenticación. Las rutas sensibles pasan por permisos específicos y CSRF en métodos `POST`.

## Permisos

- `perfil.ver`
- `perfil.editar`
- `perfil.password.cambiar`
- `perfil.foto.actualizar`
- `perfil.foto.eliminar`

La navegación “Mi perfil” se muestra únicamente si el usuario tiene `perfil.ver`.

Los botones de edición, contraseña y eliminación de foto se muestran según permiso. El controlador vuelve a validar el permiso antes de ejecutar el servicio.

## Foto activa

Estado posterior a `PERFIL-FOTO-UPLOAD-1`:

- Se muestran metadatos seguros de la foto activa.
- Se permite carga física privada de JPEG, PNG o WebP con `perfil.foto.actualizar`.
- Se permite eliminar lógicamente la foto activa si existe permiso.
- No se expone `ruta_relativa`.
- No se coloca ningún archivo privado en `public/`.

El serving público de foto sigue fuera de alcance hasta una fase posterior.

## Seguridad

- La vista escapa valores dinámicos con `e()`.
- No imprime `password_hash`.
- No imprime contraseñas.
- No imprime roles internos.
- No imprime códigos de permisos internos.
- No imprime IDs sensibles de perfil o foto.
- No permite editar `username`, `email`, `roles`, `permisos`, `activo`, `password` ni `password_hash` desde la UI.

## Prueba funcional

Comando:

```bash
php database/perfil-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Cobertura principal:

- rutas declaradas;
- permisos requeridos;
- usuario sin sesión redirigido;
- usuario sin permiso rechazado;
- `POST` sin CSRF rechazado;
- perfil visible con ADMIN;
- actualización de campos permitidos;
- campos prohibidos ignorados por controlador;
- validación de URL inválida;
- cambio de contraseña;
- metadatos de foto visibles sin ruta privada;
- formulario de carga de foto visible con permiso;
- eliminación de foto activa;
- rollback de datos QA transitorios.

## Compatibilidad con regresiones heredadas

PERFIL-UI-1 autoriza la UI privada de perfil. Por eso los DB-TEST heredados de
`PERFIL-SERVICE-1` y `PERFIL-VCARD-DB-1` deben validar su contrato original sin
bloquear artefactos privados de perfil creados por esta fase:

- `app/Http/Controllers/ProfileController.php`;
- `app/Views/profile/`;
- `public/css/modules/profile.css`.

Las regresiones deben seguir bloqueando vCard pública, QR, VCF, credencial
visual/verificable, rutas públicas `/v/`, controladores públicos y vistas/assets
públicos de vCard o credencial.

## Fuera de alcance

PERFIL-UI-1 no implementa:

- vCard pública;
- QR;
- VCF;
- credencial visual;
- productos de vCard;
- administración de usuarios;
- revocación de sesiones;
- serving público de foto;
- rutas públicas de perfil;
- cambios en productos, precios o inventario.

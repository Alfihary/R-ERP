# CORREO-OUTBOX-URL-CHECK-CORRECCION-1

## Objetivo y estado

Esta microfase corrige únicamente el falso positivo de URL absoluta del
constraint `chk_tickets_productos_correos_no_sensitive`. No cambia datos,
renderer, destinatarios, dedupe, estados, procesamiento ni transporte.

```ini
DATABASE=r_erp_db_core_0_test
PHASE_RESULT=PASS
SCHEMA_CHANGE=CHECK_CONSTRAINT_ONLY
MIGRATION_APPLIED=true
ROLLBACK_VERIFIED=true
REAL_SMTP_TEST=false
NETWORK_CONNECTIONS=0
REAL_EMAILS_SENT=0
SECRET_RESOLUTIONS=0
```

## Causa raíz

La migración histórica
`tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox`
creó el constraint con la alternativa:

```text
[a-z]:[\\/]
```

La alternativa carece de contexto anterior. En consecuencia, MySQL considera
que `https:/` contiene una ruta porque encuentra `s:/`, y que `http:/`
contiene una ruta porque encuentra `p:/`.

La expresión completa original protege conjuntamente `subject`,
`error_mensaje_seguro`, `html` y `text`, y bloquea:

- `storage/private`;
- `storage/uploads`;
- `dsn`;
- `password`;
- `secret`;
- `token`;
- rutas Windows con letra de unidad.

Todas esas protecciones se conservan.

## Regla corregida

La alternativa de ruta Windows pasa a ser:

```text
(^|[^[:alnum:]_])[a-z]:[\\/]
```

El prefijo exige inicio de texto o un delimitador que no sea alfanumérico ni
guion bajo. Así detecta `C:\\...`, `C:/...`, rutas después de espacios o
comillas y `file:///C:/...`, pero no interpreta la letra final de `http` o
`https` como unidad de disco.

La política HTTPS/HTTP continúa en la aplicación. El CHECK solamente evita el
falso positivo y no intenta duplicar esa validación.

## Matriz MySQL REGEXP

Probada directamente sobre MySQL 8.0.38:

| Caso | Resultado requerido |
|---|---|
| `https://example.test/tickets/productos/123` | Permitido |
| `https://erp.example.com/tickets/productos/34` | Permitido |
| `http://127.0.0.1:8080/tickets/productos/123` | Permitido por CHECK |
| `texto con: https://example.test/a/b` | Permitido |
| `C:\\temp\\archivo.txt` | Rechazado |
| `C:/temp/archivo.txt` | Rechazado |
| rutas anteriores dentro de texto o comillas | Rechazado |
| `file:///C:/temp/archivo.txt` | Rechazado |
| `C:\\Users\\usuario\\archivo.pdf` | Rechazado |
| `storage/private`, `storage/uploads` | Rechazado |
| `dsn`, `password`, `secret`, `token` | Rechazado |

## Migración y rollback

La migración nueva es:

```text
correo_outbox_url_check_correccion_1_001_allow_safe_absolute_urls
```

`up()`:

1. exige la migración original;
2. exige la tabla y el constraint conocidos;
3. prevalidada todas las filas con la regla corregida;
4. reemplaza el CHECK mediante un único `ALTER TABLE`;
5. no modifica filas.

`down()` restaura la expresión histórica solamente si las filas actuales la
cumplen. Si ya existen mensajes persistentes con URL absoluta, bloquea el
rollback en lugar de borrar o deformar contenido.

## Pruebas e integridad

El runner específico es:

```text
php database/correo-outbox-url-check.php <migrate|rollback|db:test|status> \
  --database=r_erp_db_core_0_test \
  --confirm-database=r_erp_db_core_0_test
```

El DB-TEST verifica definición, matriz MySQL, prevalidación, inserción HTTPS
real con rollback, rechazos reales de rutas y términos sensibles, conteos,
hashes, filas protegidas y `eligible_count`.

Línea base previa:

- `tickets_count=2`;
- `outbox_count=5`;
- `eligible_count=3`;
- outbox `1`: `CANCELADO`, intentos `0`;
- outbox `36`: `ENVIADO`, intentos `1`;
- outbox `698`, `699`, `700`: `PENDIENTE`, intentos `0`.

La única persistencia autorizada es el registro de la migración en
`schema_migrations`. Las inserciones QA usan transacción y rollback.

Resultados finales:

- migración inicial: `applied`;
- DB-TEST específico: `PASS`;
- inserción HTTPS: `ACCEPTED_AND_ROLLED_BACK`;
- rollback: `rolled_back`, restauró el falso positivo original como prueba;
- reaplicación final: `applied`;
- outbox DB: `PASS`;
- orquestación: `PASS`;
- renderer puro: `40/40 PASS`;
- retry/cancel: `44/44 PASS`;
- UI outbox: `PASS`;
- contrato mail: `PASS`.

El DB-test histórico de outbox tenía un fixture de constraint con la misma
`dedupe_key` que la prueba posterior del servicio. Se aisló ese fixture con el
sufijo `:constraint`; la regla de dedupe productiva no cambió. El auditor del
contrato se ajustó para reconocer exclusivamente la nueva migración autorizada.

## Limitaciones

Esta microfase no envía correo, no ejecuta `process`, no resuelve secretos y
no limpia filas elegibles. La implementación runtime abierta debe revalidarse
después de aplicar la migración. La migración histórica permanece intacta.

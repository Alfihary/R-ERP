# CORREO-OUTBOX-SCHEDULER-CONTRATO-1

## Estado y objetivo

Esta fase define el contrato técnico y operativo para automatizar en el futuro la ejecución de `MailOutboxProcessor`. No implementa scheduler, cron, wrapper, lock, migración, UI, ruta HTTP ni envío SMTP.

```text
HOSTING_PREREQUISITE_PENDING=true
```

La automatización seguirá bloqueada hasta verificar las capacidades reales de AwardSpace.

## Auditoría del processor actual

`MailOutboxProcessor` admite un máximo de 10 mensajes por llamada. `process()` repite el claim hasta completar el límite solicitado o hasta que no haya más filas elegibles. Al terminar el batch ejecuta `recoverStaleProcessing()`.

El repositorio selecciona, por antigüedad e ID:

```text
PENDIENTE
ERROR con intentos < max_intentos
```

Cada claim ocurre en una transacción corta:

1. `SELECT ... FOR UPDATE` selecciona una fila elegible;
2. cambia a `ENVIANDO`;
3. incrementa `intentos` una sola vez;
4. establece `ultimo_intento_at`;
5. confirma la transacción antes de abrir SMTP.

La finalización utiliza la identidad fuerte:

```text
id + intentos + ultimo_intento_at
```

Los resultados de finalización son `success`, `state_changed` y `not_found`. Un worker viejo no puede cerrar un claim nuevo.

El stale threshold actual es de 15 minutos. `ENVIANDO` vencido pasa a `ERROR` con un mensaje seguro. El scheduler no debe implementar un recovery paralelo.

La semántica continúa siendo **at-least-once**, no exactly-once. Si SMTP acepta un mensaje y el proceso muere antes de registrar `ENVIADO`, una ejecución posterior puede repetir el envío. La deduplicación evita duplicar la intención almacenada, pero no puede demostrar por sí sola que un servidor SMTP recibió un mensaje antes de un crash.

## Entrypoint actual

Runner auditado:

```text
database/tickets-productos-partidas-estados-correo-procesador.php
```

Modos actuales:

- `functional:test` usa `FakeMailTransport`;
- `dry-run` lista metadata segura y no resuelve secretos;
- `process` usa `PHPMailerMailTransport` y puede enviar correo real.

Protecciones actuales:

- `PHP_SAPI === 'cli'`;
- `--database` y `--confirm-database` deben coincidir;
- ambos nombres deben ser exactamente `r_erp_db_core_0_test`;
- solo admite `APP_ENV=local|development|test`;
- `process` exige `--confirm-real-email=YES`;
- el límite debe estar entre 1 y 10;
- errores terminan con exit code 1 y mensaje seguro;
- éxito produce JSON y exit code 0.

Por estas restricciones, el runner actual **no es apto para cron de producción tal cual**. Es deliberadamente un runner de QA controlada. No debe debilitarse para convertirlo en scheduler.

## Arquitectura futura

La primera implementación deberá crear un wrapper CLI específico, por ejemplo:

```text
database/mail-outbox-scheduler.php
```

Responsabilidades exclusivas del wrapper:

1. comprobar CLI;
2. cargar configuración y aplicar `APP_TIMEZONE`;
3. validar ambiente y doble confirmación de base;
4. adquirir el lock global;
5. ejecutar un batch de `MailOutboxProcessor`;
6. emitir un resumen seguro;
7. liberar el lock al cerrar conexión;
8. devolver un exit code documentado.

La lógica cron, locking y observabilidad no debe incorporarse dentro de `MailOutboxProcessor`.

## Frecuencia recomendada

Recomendación inicial:

```text
cada 5 minutos
```

Comparación:

| Frecuencia | Capacidad teórica con batch 10 | Consideración |
|---|---:|---|
| 1 minuto | 600 mensajes/hora | Mejor para backlog, más procesos y carga |
| 5 minutos | 120 mensajes/hora | Balance inicial recomendado |
| 10 minutos | 60 mensajes/hora | Latencia perceptible y recuperación lenta |
| 15 minutos | 40 mensajes/hora | Coincide con stale threshold y deja poco margen |

La frecuencia de 5 minutos ofrece latencia razonable y permite terminar una ejecución antes del siguiente ciclo. No debe asumirse que AwardSpace permite esa frecuencia.

La programación debe ser por intervalo, no por una hora civil exacta, para evitar dependencia frágil del horario de verano.

## Duración máxima

Timeout operativo recomendado:

```text
4 minutos
```

Debe permanecer por debajo de los 15 minutos del stale threshold. El límite deberá aplicarse mediante capacidades confirmadas del hosting o un mecanismo seguro del wrapper; no mediante terminación abrupta que deje secretos o logs incompletos.

PHPMailer usa actualmente un timeout SMTP de 20 segundos por intento. El tiempo total real depende de DNS, conexión, destinatarios y disponibilidad del servidor.

## Batch y backlog

Primera versión:

```text
un batch por ejecución
batch máximo actual = 10
```

No se recomienda vaciar toda la cola en un loop ilimitado. Un batch acota duración, consumo de memoria, conexiones SMTP y riesgo de superar límites del hosting.

Con 1000 mensajes, batch 10 y frecuencia de 5 minutos:

```text
100 ejecuciones
500 minutos
aproximadamente 8 horas 20 minutos
```

Respuesta futura a backlog, en orden:

1. medir candidatos, enviados y errores;
2. reducir frecuencia si el hosting lo permite;
3. evaluar aumentar batch dentro de una fase separada;
4. evaluar loop limitado por tiempo;
5. no cambiar varios parámetros simultáneamente sin DB-TEST.

El retry automático ya está incluido: `ERROR` con intentos disponibles vuelve a ser elegible. El scheduler solo ejecuta el processor y no implementa retry propio ni backoff nuevo.

## Exclusión de overlapping

Nunca debe ejecutarse el processor sin un lock global válido.

### Recomendación principal

Usar un advisory lock MySQL como autoridad, sujeto a verificación en AwardSpace:

```sql
SELECT GET_LOCK('r_erp_mail_outbox_scheduler', timeout)
```

Ventajas:

- coordinación entre procesos que usan la misma base;
- no requiere migración;
- no depende de que los procesos compartan filesystem local;
- se libera cuando se cierra la conexión.

Riesgos:

- AwardSpace puede restringir `GET_LOCK()`;
- debe comprobarse que todas las ejecuciones usan el mismo servidor MySQL;
- la conexión propietaria debe permanecer abierta durante toda la ejecución;
- una reconexión pierde ownership;
- debe probarse liberación y timeout reales.

Si `GET_LOCK()` no está disponible, la implementación queda bloqueada hasta aprobar otra garantía. No se ejecutará sin lock.

### Lock de archivo

`flock()` o `fopen()` exclusivo puede ser una defensa adicional en un host único. La ubicación futura sería:

```text
storage/private/locks/mail-outbox-scheduler.lock
```

El nombre debe ser fijo, no provenir de input, y nunca debe estar bajo `public/`. El proceso conserva el handle abierto; no borra el archivo de otro proceso. Un lock local no es suficiente si existen múltiples hosts o filesystems no compartidos.

### Otras alternativas

- Lock file exclusivo: simple, pero depende del filesystem y topología.
- `GET_LOCK()`: recomendado como lock autoritativo si el hosting lo soporta.
- Tabla persistente: permite lease y observabilidad, pero requiere migración, expiración y recuperación; fuera de esta fase.
- Combinación DB + archivo: defensa adicional, con mayor complejidad; el DB lock seguiría siendo la autoridad multi-host.

No se asumirá que AwardSpace usa un solo host o que el filesystem es compartido.

## Política cuando el lock está ocupado

Resultado futuro:

```text
scheduler_skipped_already_running
```

El wrapper no ejecuta processor, no toca filas outbox y termina rápidamente. La decisión inicial es exit code 0 porque el overlap evitado es un resultado operativo normal, no una falla crítica. El resumen debe registrar `lock_acquired=false` y `skipped=true`.

Un error al consultar o mantener el mecanismo de lock sí es fallo operativo y termina con código no cero.

## Exit codes futuros

```text
0 = éxito, cola vacía o ejecución omitida porque otro scheduler posee el lock
1 = fallo operativo de DB, SMTP no controlado, filesystem, fatal o timeout
2 = configuración, argumentos o entorno inválidos
3 = fallo del mecanismo de lock, distinto de lock ocupado
```

Los códigos deberán probarse y mantenerse estables para monitoreo. El runner actual solo diferencia éxito 0 y fallo 1; esto no debe confundirse con el contrato futuro.

## Logging

Cada ejecución debe producir un resumen estructurado y seguro con:

- timestamp de inicio y fin;
- duración;
- batch solicitado;
- candidatos observados, si el contrato puede obtenerlos sin carrera engañosa;
- claimed;
- sent;
- errors;
- skipped;
- stale recovered;
- lock acquired;
- resultado y exit code.

No registrar:

- contraseña SMTP o valor del secret;
- `smtp_secret_ref` cuando no sea necesario;
- credenciales o DSN;
- cuerpos HTML/texto;
- destinatarios completos;
- stack traces en el log operativo;
- rutas físicas sensibles.

Primera versión recomendada: JSON seguro por stdout y errores seguros por stderr. El cron puede redirigirlos a almacenamiento privado solo después de verificar permisos.

Si se usa archivo:

```text
storage/private/logs/mail-outbox-scheduler.log
```

Debe existir rotación por tamaño o por tiempo. No se permite crecimiento infinito ni inclusión del log en deploy o Git.

No se crea tabla de observabilidad en esta fase.

## Observabilidad futura

Métricas mínimas:

- `last_run_at`;
- `last_success_at`;
- `last_failure_at`;
- `last_duration`;
- `last_claimed`;
- `last_sent`;
- `last_errors`;
- backlog elegible;
- stale recovered;
- skips por lock.

La persistencia puede requerir una tabla o almacenamiento controlado en otra fase. No debe sobrecargarse `auditoria_eventos` con heartbeats cada cinco minutos sin política de retención.

Una UI futura se separará como:

```text
CORREO-OUTBOX-SCHEDULER-STATUS-UI-1
```

Podrá mostrar última ejecución, último éxito/error, lock y backlog, pero no se implementa ahora.

## Seguridad CLI y entorno

El scheduler será exclusivamente CLI:

```php
PHP_SAPI === 'cli'
```

No existirá endpoint HTTP, ruta web, controlador ni disparador JavaScript.

Dependencias de configuración:

- `APP_ENV`;
- `APP_TIMEZONE`;
- `APP_DB_HOST`, `APP_DB_PORT`, `APP_DB_NAME`, `APP_DB_CHARSET`;
- credenciales DB disponibles mediante `.env` protegido;
- cuenta activa en `mail_accounts`;
- host, puerto, cifrado, username y `smtp_secret_ref`;
- valor del secret referenciado, resuelto por `Env::get()`;
- `vendor/autoload.php` y PHPMailer.

Las contraseñas DB/SMTP y secrets no se pasan como argumentos del cron. El flag `--confirm-real-email=YES` no es secreto; es una confirmación explícita del efecto.

La doble confirmación de base debe conservarse:

```text
--database=<base esperada>
--confirm-database=<misma base>
```

El wrapper futuro no debe tener hardcodeada la base de QA. El nombre autorizado de producción deberá definirse durante deploy y compararse también contra `APP_DB_NAME`.

## Working directory y PHP CLI

El comando futuro debe usar rutas absolutas y establecer CWD explícito. Ejemplo conceptual, no ejecutable todavía:

```text
cd /ruta/absoluta/R-ERP &&
/ruta/php database/mail-outbox-scheduler.php run \
  --database=<base> \
  --confirm-database=<base> \
  --confirm-real-email=YES
```

No se asumirá `/usr/bin/php`. Antes de deploy deben verificarse en el hosting:

```text
which php
php -v
```

También deben comprobarse PHP CLI, extensiones, límites de tiempo/memoria y acceso al árbol privado.

## Composer y vendor

`bootstrap/autoload.php` carga `vendor/autoload.php` cuando existe. `PHPMailerMailTransport` necesita PHPMailer; por ello el artefacto de deploy debe incluir `vendor/` preparado y verificado localmente si el hosting no ejecuta Composer.

`vendor/` permanece ignorado por Git. No se ejecutará Composer en AwardSpace sin confirmar capacidad y política del plan.

## Timezone

El ERP configura `APP_TIMEZONE`, actualmente con fallback `America/Mexico_City`. El bootstrap web aplica `date_default_timezone_set()`, pero el bootstrap DB del runner actual solo carga la configuración y no aplica explícitamente la zona.

El wrapper futuro debe validar y aplicar `APP_TIMEZONE` una sola vez antes de generar timestamps de aplicación. Los timestamps MySQL seguirán el contrato actual de la conexión/servidor; cualquier normalización adicional requerirá auditoría separada.

La frecuencia por intervalo evita depender de cambios DST.

## Fallos y respuesta segura

| Escenario | Respuesta futura |
|---|---|
| DB no disponible | Log seguro, exit no-cero, sin loop infinito |
| SMTP no disponible | Processor marca `ERROR`; siguiente ejecución maneja retry elegible |
| Secret ausente | Error seguro, fila `ERROR`, sin imprimir referencia/valor |
| Lock ocupado | Skip rápido, no processor, exit 0 |
| Lock fallido | Log seguro, exit 3, no processor |
| PHP fatal | stderr del hosting sin secretos; monitoreo detecta falta de éxito |
| Timeout | Proceso termina; claims quedan protegidos y stale recovery actúa después de 15 min |
| Filesystem no escribible | Mantener stdout/stderr o fallar de forma controlada; nunca escribir en `public/` |

No se añade retry infinito dentro de una ejecución. Un fallo SMTP no debe desactivar permanentemente el scheduler.

## Multiple workers y ejecución manual

La primera versión usará un solo scheduler worker. Las protecciones de claim toleran concurrencia accidental, pero no sustituyen el lock global ni justifican múltiples workers iniciales.

El comando manual debe seguir disponible de forma controlada. Cuando exista el wrapper, la ejecución manual real deberá adquirir el mismo lock autoritativo que cron. `dry-run` podrá ejecutarse sin SMTP, pero debe documentarse si toma lock de lectura o solo consulta elegibles.

## Dry-run futuro

El wrapper deberá ofrecer un modo que:

- valide CLI, entorno, autoload, DB y lock compatible;
- no use `PHPMailerMailTransport`;
- no resuelva secrets;
- no cambie la outbox;
- muestre únicamente metadata segura;
- funcione con ruta absoluta y CWD independiente.

La prueba real SMTP permanecerá en una fase y autorización separadas.

## AwardSpace pendiente

Solo se auditó información local del repositorio. Antes de implementar o desplegar deben verificarse directamente en la cuenta real:

- disponibilidad de cron;
- frecuencia mínima permitida;
- PHP CLI y ruta binaria;
- versión y extensiones PHP;
- timeout, memoria y procesos simultáneos;
- topología de hosts;
- semántica del filesystem y soporte de `flock()`;
- soporte y permisos de MySQL `GET_LOCK()`/`RELEASE_LOCK()`;
- persistencia, cuota y rotación de logs;
- conectividad SMTP saliente;
- despliegue de `vendor/`;
- ubicación privada de `.env`, locks y logs.

```text
HOSTING_PREREQUISITE_PENDING=true
```

No se afirma que AwardSpace soporte cron, PHP CLI, locks o la frecuencia recomendada.

## Plan de pruebas futuro

La fase de implementación deberá cubrir como mínimo:

1. rechazo fuera de CLI;
2. lock adquirido;
3. lock ocupado;
4. una ejecución exitosa;
5. cola vacía;
6. un pendiente;
7. un error elegible;
8. error agotado ignorado;
9. cancelado ignorado;
10. enviado ignorado;
11. `ENVIANDO` no recuperado antes del umbral;
12. stale recuperado;
13. ausencia de overlap;
14. dos procesos scheduler;
15. contención manual contra scheduler;
16. DB no disponible;
17. secret ausente;
18. SMTP falso exitoso;
19. SMTP falso con error;
20. exit code de éxito;
21. exit codes de fallo/configuración/lock;
22. saneamiento de logs;
23. ausencia de secrets en comando y salida;
24. independencia del CWD;
25. uso de rutas absolutas;
26. respeto del batch;
27. comportamiento con backlog;
28. semántica at-least-once preservada;
29. identidad fuerte del claim preservada;
30. cleanup, hashes e integridad.

Los tests usarán `FakeMailTransport`. SMTP real requiere autorización separada.

## Datos protegidos

Esta fase no escribe base de datos. Deben permanecer intactos:

```text
ticket 34 / QASMTP-000001 / EN_REVISION
outbox 1 / CANCELADO / intentos 0
outbox 36 / ENVIADO / intentos 1
eligible_count = 0
```

## Decisiones del contrato

```text
FREQUENCY_RECOMMENDED=5m
EXECUTION_TIMEOUT_RECOMMENDED=4m
BATCH_POLICY=single_batch
BATCH_SIZE_CURRENT=10
LOCK_AUTHORITY=mysql_get_lock_pending_hosting_verification
LOCK_BUSY_EXIT_CODE=0
CLI_ONLY=true
AT_LEAST_ONCE=true
REAL_SMTP_TEST=false
HOSTING_PREREQUISITE_PENDING=true
```

## Fuera de alcance y siguiente fase

No se implementaron cron, scheduler, wrapper, lock, logging persistente, tabla de observabilidad, UI, SMTP, rutas, permisos, migraciones ni deploy.

La siguiente fase posible, después de cerrar este contrato y verificar hosting, será `CORREO-OUTBOX-SCHEDULER-IMPLEMENTACION-1`. Si primero se requiere validar AwardSpace, debe abrirse una microfase independiente de prerrequisitos de hosting sin desplegar.

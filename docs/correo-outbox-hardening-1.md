# CORREO-OUTBOX-HARDENING-1

## Objetivo

Endurecer las primitivas internas que finalizan un claim de `tickets_productos_correos` sin cambiar selección, transporte, reintentos automáticos, límites de batch ni configuración SMTP.

## Riesgo previo

El repositorio contenía dos niveles de métodos mutables:

- `markClaimSent()` y `markClaimError()` exigían únicamente `status = ENVIANDO`;
- los métodos heredados `markSent()` y `markError()` actualizaban por ID sin validar estado y además incrementaban `intentos` durante la finalización.

La validación exclusiva por estado impedía algunas escrituras fuera de secuencia, pero no distinguía el claim original de uno posterior. Un worker atrasado podía observar nuevamente `ENVIANDO` después de stale recovery, retry y un segundo claim, y cerrar el trabajo del worker nuevo.

## Mutaciones auditadas

`ProductTicketEmailOutboxRepository` concentra las mutaciones de la cola:

- `insertPending()`: crea una fila `PENDIENTE` con deduplicación única;
- `claimNextEligible()`: reclama `PENDIENTE` o `ERROR` elegible;
- `markSent()` / `markClaimSent()`: finaliza el claim como `ENVIADO`;
- `markError()` / `markClaimError()`: finaliza el claim como `ERROR`;
- `recoverStale()`: recupera `ENVIANDO` vencidos a `ERROR`;
- `MailOutboxActionRepository::retryError()`: `ERROR -> PENDIENTE`;
- `MailOutboxActionRepository::cancelPendingOrError()`: `PENDIENTE|ERROR -> CANCELADO`.

No existen eliminaciones operativas de outbox. Los `DELETE` encontrados pertenecen únicamente a cleanup de pruebas.

## Transiciones

```text
PENDIENTE o ERROR elegible -> ENVIANDO
ENVIANDO + claim exacto    -> ENVIADO
ENVIANDO + claim exacto    -> ERROR
ENVIANDO stale             -> ERROR
ERROR                      -> PENDIENTE  (retry administrativo)
PENDIENTE o ERROR          -> CANCELADO  (cancelación administrativa)
```

`ENVIADO` y `CANCELADO` permanecen terminales.

## Identidad del claim

No fue necesaria una migración. La versión del claim se deriva de columnas existentes:

```text
id + intentos + ultimo_intento_at
```

El claim incrementa `intentos` exactamente una vez y devuelve la fila ya actualizada. El processor conserva esos valores y los presenta al finalizar. Tanto estado como versión deben coincidir:

```sql
WHERE id = :id
  AND status = 'ENVIANDO'
  AND intentos = :expected_attempts
  AND ultimo_intento_at = :expected_last_attempt_at
```

Aunque `ultimo_intento_at` tiene precisión de segundos, `intentos` cambia en cada claim. La combinación evita que dos claims consecutivos compartan identidad aun si ocurren dentro del mismo segundo. El timestamp utilizado es el valor devuelto directamente por MySQL, sin reformatearlo en PHP.

## Resultado de transición

Las finalizaciones devuelven un resultado explícito:

- `success`: exactamente una fila fue modificada;
- `state_changed`: la fila existe, pero estado o versión ya no coincide;
- `not_found`: el ID no existe.

`rowCount() = 0` nunca se interpreta como éxito. Una carrera esperable no produce un error fatal en `MailOutboxProcessor`; el processor reporta el estado observado y el resultado de transición.

Las llamadas heredadas sin identidad de claim se conservan como no-op seguro con `state_changed`. Así no pueden sobrescribir una fila y señalan que el caller debe migrar al contrato versionado.

## Campos preservados

El incremento de `intentos` ocurre únicamente durante claim. `markSent()` y `markError()` no incrementan ni reinician intentos.

Ambas finalizaciones preservan:

- `dedupe_key`;
- `intentos` y `max_intentos`;
- `ultimo_intento_at`;
- ticket, evento, plantilla y destinatarios.

`markSent()` establece `status = ENVIADO`, `enviado_at = CURRENT_TIMESTAMP`, limpia el error seguro y actualiza `updated_at`.

`markError()` establece `status = ERROR`, mantiene `enviado_at = NULL`, guarda únicamente `error_mensaje_seguro` y actualiza `updated_at`.

El mensaje seguro se normaliza, se limita a 500 caracteres y rechaza referencias a passwords, secrets, tokens, DSN y rutas privadas.

## Concurrencia y stale recovery

Escenario verificado:

1. worker A reclama con intento 1;
2. stale recovery mueve la fila a `ERROR`;
3. la fila vuelve a ser elegible;
4. worker B reclama con intento 2;
5. worker A intenta finalizar usando intento 1;
6. la finalización de A devuelve `state_changed`;
7. worker B puede finalizar usando la identidad del intento 2.

También se verificó que:

- solo la primera de dos llamadas `markSent()` gana;
- solo la primera de dos llamadas `markError()` gana;
- sent seguido de error conserva `ENVIADO`;
- error seguido de sent conserva `ERROR`;
- una finalización tardía no sobrescribe `CANCELADO`, `ENVIADO`, `PENDIENTE` ni `ERROR`;
- stale recovery y finalización compiten mediante updates condicionados por estado.

## Processor

`MailOutboxProcessor` fue adaptado únicamente para propagar `intentos` y `ultimo_intento_at` del claim y consumir `success`, `state_changed` o `not_found`.

No se modificaron:

- selección de elegibles;
- tamaño de batch;
- exclusión dentro del mismo batch;
- stale threshold;
- transporte;
- resolución de secretos;
- configuración SMTP;
- semántica de retry;
- modelo at-least-once.

## Pruebas

El test funcional del processor cubre:

- finalización válida desde `ENVIANDO`;
- rechazo desde `PENDIENTE`, `ERROR`, `ENVIADO` y `CANCELADO`;
- doble sent y doble error;
- carreras sent/error y error/sent;
- preservación de intentos, dedupe y último intento;
- error seguro y `enviado_at` solo en éxito;
- clasificación de `rowCount() = 0`;
- claim A, stale recovery y claim B;
- rechazo del worker viejo sobre el claim nuevo;
- no-op seguro de métodos heredados sin identidad.

Los fixtures se eliminan completamente y las regresiones verifican los datos protegidos, hashes, `eligible_count = 0`, cero conexiones de red, cero correos reales y cero resoluciones de secretos.

## Limitaciones y esquema futuro

Para el modelo actual, la versión compuesta ofrece identidad fuerte sin cambio de esquema:

```text
CLAIM_IDENTITY_STRONG=true
SCHEMA_HARDENING_REQUIRED=false
```

Un `claim_token` o `worker_token` solo sería necesario si una fase futura cambia el contador de intentos, habilita claims paralelos para la misma fila o requiere ownership distribuido independiente. Cualquier cambio así deberá tener migración y DB-TEST propios.

Scheduler, resend y ejecución SMTP real permanecen fuera de esta fase.

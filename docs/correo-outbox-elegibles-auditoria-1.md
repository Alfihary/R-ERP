# CORREO-OUTBOX-ELEGIBLES-AUDITORIA-1

## Objetivo y alcance

Auditoría estrictamente de solo lectura de las filas `698`, `699` y `700` de
`tickets_productos_correos` en `r_erp_db_core_0_test`. No se ejecutó el
procesador, no se reclamaron filas, no se resolvieron secretos y no hubo SMTP.

La auditoría se realizó en la rama `jesus`, HEAD `e775380`, sincronizada 0/0
con `origin/jesus`. El worktree inicial estaba limpio y el staging vacío.

## Snapshot inicial

| Métrica | Valor |
| --- | ---: |
| `tickets_count` | 2 |
| `outbox_count` | 5 |
| `eligible_count` | 3 |
| `ENVIANDO` | 0 |

Hashes calculados sobre todas las columnas, ordenadas por `id`, solo para
comparar esta ejecución:

- `tickets_productos`: `3a3903b75e3f9acb7e6e60bc35da5b215cf1fb42e73cfa602d691e66153ab3b7`
- `tickets_productos_correos`: `24a0d94be2e42b2744f7da68c0b9f38a01f481cceec76091d4c8a15e84045a9f`

## Contexto del ticket

Las tres filas pertenecen al ticket `3`, folio `BO-000013`, empresa
`Tlalnepantla`, almacén `Otumba`. Su estado actual es `APROBADO`, con dos
partidas aprobadas, cero rechazadas y sin eliminación lógica.

- Partida `3`, número 1: `APROBADA`, resuelta el `2026-09-29 10:30:47`.
- Partida `4`, número 2: `APROBADA`, resuelta el `2026-09-29 10:31:32`.
- El ticket quedó actualizado el `2026-09-29 10:31:32`.

El solicitante y creador persistido de las tres filas es `jesus.g`, no una
cuenta con prefijo `qa.`. No existen eventos en `auditoria_eventos` para el
ticket 3 ni para las filas 698/699/700.

## Filas auditadas

### Outbox 698

```ini
OUTBOX_ID=698
EVENT=PARTIDA_APROBADA
TICKET_ID=3
PARTIDA_ID=3
FOLIO=BO-000013
OUTBOX_STATUS=PENDIENTE
ATTEMPTS=0
MAX_ATTEMPTS=3
ELIGIBLE=true
CURRENT_TICKET_STATE=APROBADO
CURRENT_LINE_STATE=APROBADA
EVENT_CURRENTLY_CONSISTENT=CONSISTENT
LIKELY_QA_FIXTURE=INSUFFICIENT_EVIDENCE
RISK_IF_PROCESSED=REAL_RECIPIENTS_AND_DELAYED_NOTIFICATION
RECOMMENDED_ACTION=REVIEW_BEFORE_CANCEL
```

- Template: `line_approved`, coherente con `PARTIDA_APROBADA`.
- Creación: `2026-09-29 10:30:48`.
- Último intento, envío y cancelación: `NULL`.
- Dedupe: `ticket:3:partida:3:evento:PARTIDA_APROBADA`; aparece una vez.
- Subject: `Partida aprobada en solicitud BO-000013`; no vacío y sin CR/LF.
- Envelope: TO=2, CC=0, BCC=0. Destinatarios distintos, enmascarados como
  `s***@gruporefrigerantes.com.mx` y `s***@gruporefrigerantes.com.mx`.
- HTML: presente, 420 bytes, SHA-256
  `62f0dd24961ef1940a0212df59618f62e417229c4729124297739cba4ab7d492`.
- Texto: presente, 280 bytes, SHA-256
  `8bdaf7e3f254015804b773c4fd99a15a1042e284b7f95e122898bebd8552e556`.
- Hash de fila sanitizada:
  `cfe0b319950e22eda4047b359017667a58b4980a6d60c17b80c69f3bbdeb9912`.
- Riesgo: enviaría a dos buzones reales un aviso antiguo de aprobación.

### Outbox 699

```ini
OUTBOX_ID=699
EVENT=PARTIDA_APROBADA
TICKET_ID=3
PARTIDA_ID=4
FOLIO=BO-000013
OUTBOX_STATUS=PENDIENTE
ATTEMPTS=0
MAX_ATTEMPTS=3
ELIGIBLE=true
CURRENT_TICKET_STATE=APROBADO
CURRENT_LINE_STATE=APROBADA
EVENT_CURRENTLY_CONSISTENT=CONSISTENT
LIKELY_QA_FIXTURE=INSUFFICIENT_EVIDENCE
RISK_IF_PROCESSED=REAL_RECIPIENTS_AND_DELAYED_NOTIFICATION
RECOMMENDED_ACTION=REVIEW_BEFORE_CANCEL
```

- Template: `line_approved`, coherente con `PARTIDA_APROBADA`.
- Creación: `2026-09-29 10:31:32`.
- Último intento, envío y cancelación: `NULL`.
- Dedupe: `ticket:3:partida:4:evento:PARTIDA_APROBADA`; aparece una vez.
- Subject: `Partida aprobada en solicitud BO-000013`; no vacío y sin CR/LF.
- Envelope: TO=2, CC=0, BCC=0; mismos dominios enmascarados que la fila 698.
- HTML: presente, 422 bytes, SHA-256
  `e1a4179fd374abbb385b262fe72e4bc6be633eace278c6b9f0852bf885905b66`.
- Texto: presente, 282 bytes, SHA-256
  `0903bcb25369277788173edfbec01ba2b0229e99ac549f03512eda885c23c38a`.
- Hash de fila sanitizada:
  `e816eaa1372f309a407331ca886bf5bac2f62a248149877441a0784e9d083cb0`.
- Riesgo: enviaría a dos buzones reales un aviso antiguo de aprobación.

### Outbox 700

```ini
OUTBOX_ID=700
EVENT=TICKET_RESUELTO_TOTAL
TICKET_ID=3
PARTIDA_ID=NULL
FOLIO=BO-000013
OUTBOX_STATUS=PENDIENTE
ATTEMPTS=0
MAX_ATTEMPTS=3
ELIGIBLE=true
CURRENT_TICKET_STATE=APROBADO
EVENT_CURRENTLY_CONSISTENT=CONSISTENT
LIKELY_QA_FIXTURE=INSUFFICIENT_EVIDENCE
RISK_IF_PROCESSED=REAL_RECIPIENTS_AND_DELAYED_FINAL_RESOLUTION
RECOMMENDED_ACTION=REVIEW_BEFORE_CANCEL
```

- Template: `ticket_resolved`, coherente con `TICKET_RESUELTO_TOTAL`.
- Creación: `2026-09-29 10:31:32`.
- Último intento, envío y cancelación: `NULL`.
- Dedupe: `ticket:3:partida:null:evento:TICKET_RESUELTO_TOTAL`; aparece una vez.
- Subject: `Solicitud de alta de producto BO-000013 resuelta`; no vacío y sin
  CR/LF.
- Envelope: TO=2, CC=0, BCC=0; mismos dominios enmascarados que las anteriores.
- HTML: presente, 452 bytes, SHA-256
  `46ceada7f9c52c0b5cb3b855ca2e370c2cc6d4e43263d0ce7cdc656797149bb3`.
- Texto: presente, 318 bytes, SHA-256
  `0645c6a1d035e227a7ee4f82891d31a49e8483075b8fa12608e15647584cc033`.
- Hash de fila sanitizada:
  `f089e2db372db9f35eb5ba3ff9cc238acfb407cd1150595ef4d0361ae68f12c8`.
- Riesgo: enviaría a dos buzones reales un aviso de resolución ya antiguo,
  después de los dos avisos de partida si el lote conserva el orden actual.

## Evidencia sobre el origen

### Hechos

- Las filas existen antes de esta fase y son las únicas tres elegibles.
- Documentación y DB-TEST posteriores las preservan como datos preexistentes.
- `docs/correo-runtime-persistido-qa-1.md` aclara que 698/699/700 no fueron
  fixtures de escritura de ese test.
- `git log -S"BO-000013"` no encuentra el folio en código o documentación.
- El ticket y las filas fueron operados por `jesus.g`; no hay actor `qa.*`.
- No hay eventos de auditoría que identifiquen una prueba concreta.

### Inferencia

La fecha coincide con trabajo local del subsistema de correo, por lo que
podrían provenir de una comprobación manual. Esa coincidencia no basta para
clasificarlas como fixtures: el folio parece operativo y los destinatarios
pertenecen a un dominio real. El origen queda como `INSUFFICIENT_EVIDENCE`.

## Elegibilidad y comportamiento del processor

El repositorio considera elegible una fila cuando:

```text
status = PENDIENTE
OR (status = ERROR AND intentos < max_intentos)
```

Las tres cumplen por estar `PENDIENTE`. Si se ejecutara el processor:

1. seleccionaría por `created_at`, luego `id`;
2. reclamaría atómicamente una fila como `ENVIANDO`;
3. incrementaría `intentos` y fijaría `ultimo_intento_at`;
4. cargaría la cuenta de correo y resolvería el secreto si el transporte lo exige;
5. invocaría `MailTransport`;
6. finalizaría como `ENVIADO` o `ERROR` mediante identidad del claim;
7. recuperaría como `ERROR` filas `ENVIANDO` con más de 15 minutos.

En esta fase no se ejecutó ninguno de esos pasos. Procesar el lote actual
podría producir tres mensajes y seis entregas a buzones reales.

## Datos protegidos

- Ticket 34: `QASMTP-000001`, `EN_REVISION`, `total_partidas=0`.
- Outbox 1: `CANCELADO`, `intentos=0`.
- Outbox 36: `ENVIADO`, `intentos=1`, `enviado_at` presente.
- No existe ninguna fila en `ENVIANDO`; no aplica recuperación stale.

## Conclusión y recomendación

Las filas son técnicamente coherentes con el estado actual del ticket, pero
son antiguas y apuntan a destinatarios reales. No deben procesarse ni
cancelarse sin decisión humana. La acción recomendada para las tres es
`REVIEW_BEFORE_CANCEL` en una microfase separada, manteniendo prohibido el
envío hasta decidir explícitamente si esas notificaciones siguen siendo
operativamente necesarias.

Esta auditoría no ejecutó SMTP, no abrió conexiones externas, no resolvió
secretos y no realizó escrituras en la base.

```ini
DB_WRITES=0
SMTP_CONNECTIONS=0
EMAILS_SENT=0
APP_MAIL_NETWORK_CONNECTIONS=0
SECRET_RESOLUTIONS=0
PROCESS_EXECUTIONS=0
CANCELLATION_DECISIONS_EXECUTED=0
```

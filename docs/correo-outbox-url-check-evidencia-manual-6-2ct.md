# Evidencia manual de TP-MAIL-6.2CT

## Ejecución y alcance

Resultados de la ejecución manual confirmados por el usuario. La prueba se realizó exclusivamente con SELECT. No hubo DDL ni DML.

```ini
R_ERP_TP_MAIL_6_2CT_MANUAL_EXECUTION=PASS

Q01=PASS
Q02=PASS
Q03=PASS
Q04=PASS
Q05=PASS
Q06=PASS
Q07=PASS

SELECT_ONLY_CONFIRMED=true
DDL_EXECUTED=false
DML_EXECUTED=false
BLOCKED_GUARDS_FOUND=0
```

## Evidencia por consulta

- **Q01** — `IDENTITY_PASS`.
- **Q02** — `CONTEXT_PASS`.
- **Q03** — `SCHEMA_METADATA_PASS`.
- **Q04** — La migración y el contrato tienen el mismo patrón textual, longitud 41 y el mismo HEX. La reconstrucción física tiene longitud 43. Resultados `MIGRATION_CONTRACT_TEXT_MATCH` y `PATTERN_METADATA_PASS`.
- **Q05** — Pasaron todos los casos sintéticos evaluados. `migration_contract_null_safe_equal = 1` y `migration_physical_null_safe_equal = 1`. Resultado `SYNTHETIC_PATTERN_PASS`.
- **Q06** — `migration_result`, `contract_result` y `physical_reconstruction_result` coincidieron fila por fila para los casos sintéticos evaluados. Resultado `CONCAT_WS_PASS`.
- **Q07** — El literal convencional y el literal `_utf8mb4` tienen HEX iguales y longitud 41. `introducer_case_sensitive_result = 0`. Resultado `INTRODUCER_PASS`.

## Resultados y límites

```ini
CONTRACT_RESULT=PASS_CONTRACT_ALIGNED_WITH_MIGRATION
RUNNER_RESULT=PASS_DB_FREE_CONTRACT_AND_SECURITY_TESTS
PHYSICAL_RECONSTRUCTION_RESULT=PASS_OBSERVED_CASES_ONLY_NOT_UNIVERSAL
```

La forma física sigue siendo una reconstrucción funcional. No se afirma que la expresión física se ejecutara directamente desde `CHECK_CLAUSE`. La conclusión se limita a los casos observados. No se afirma equivalencia universal.

## Cierre y referencia histórica

```ini
FILES_MODIFIED_DURING_6_2CT=0
COMMIT_DURING_6_2CT=false
PUSH_DURING_6_2CT=false
DEPLOY_DURING_6_2CT=false
PHASE_CLOSURE_READY=true
NEXT_PHASE_READY=true
```

El antecedente histórico [Corrección del CHECK de URL del outbox](correo-outbox-url-check-correccion-1.md) describe la corrección de `chk_tickets_productos_correos_no_sensitive`. La evidencia manual de este documento corresponde a `chk_mail_accounts_no_plain_secret`.

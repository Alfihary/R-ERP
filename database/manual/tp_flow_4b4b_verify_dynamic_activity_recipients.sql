-- TP-FLOW-4B4B SELECT-only verification package.
-- No DML is present in this file.

SELECT DATABASE() AS target_database, CURRENT_USER() AS executing_account;

SELECT
    evento,
    COUNT(*) AS rule_count,
    MIN(activo) AS all_active,
    MIN(mail_account_id) AS mail_account_id,
    MIN(enviar_solicitante) AS enviar_solicitante,
    MIN(enviar_responsables) AS enviar_responsables,
    SUM(to_json IS NULL) AS null_to_count,
    SUM(cc_json IS NULL) AS null_cc_count,
    SUM(bcc_json IS NULL) AS null_bcc_count
FROM tickets_productos_correo_reglas
WHERE evento IN ('COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO')
GROUP BY evento
ORDER BY evento;

SELECT
    CONSTRAINT_NAME,
    CHECK_CLAUSE
FROM information_schema.check_constraints
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN (
      'chk_tickets_productos_correo_reglas_evento',
      'chk_tickets_productos_correos_evento',
      'chk_tickets_productos_correos_plantilla'
  )
ORDER BY CONSTRAINT_NAME;

SELECT
    migration,
    applied_at
FROM schema_migrations
WHERE migration = 'tp_partidas_estados_correo_config_1_003_clear_activity_static_recipients';

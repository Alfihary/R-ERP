<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_config_1_001_create_mail_configuration';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE mail_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                from_email VARCHAR(190) NOT NULL,
                from_name VARCHAR(120) NOT NULL,
                reply_to_email VARCHAR(190) NULL,
                smtp_host VARCHAR(190) NOT NULL,
                smtp_port SMALLINT UNSIGNED NOT NULL,
                smtp_encryption VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                smtp_username VARCHAR(190) NOT NULL,
                smtp_secret_ref VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mail_accounts_codigo (codigo),
                KEY idx_mail_accounts_activo (activo),
                CONSTRAINT chk_mail_accounts_codigo CHECK (
                    codigo REGEXP '^[A-Z0-9_]{3,60}$'
                ),
                CONSTRAINT chk_mail_accounts_from_email CHECK (
                    from_email NOT REGEXP '[[:space:]]'
                    AND from_email REGEXP '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                ),
                CONSTRAINT chk_mail_accounts_reply_to_email CHECK (
                    reply_to_email IS NULL
                    OR (
                        reply_to_email NOT REGEXP '[[:space:]]'
                        AND reply_to_email REGEXP '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                    )
                ),
                CONSTRAINT chk_mail_accounts_smtp_port CHECK (
                    smtp_port BETWEEN 1 AND 65535
                ),
                CONSTRAINT chk_mail_accounts_smtp_encryption CHECK (
                    smtp_encryption IN ('none', 'tls', 'ssl')
                ),
                CONSTRAINT chk_mail_accounts_smtp_secret_ref CHECK (
                    smtp_secret_ref REGEXP '^[A-Z0-9_]{3,120}$'
                ),
                CONSTRAINT chk_mail_accounts_no_plain_secret CHECK (
                    LOWER(CONCAT_WS(' ', smtp_host, smtp_username, from_email, reply_to_email)) NOT REGEXP
                    '(password|secret|token|dsn|[a-z]:[\\\\/]|=)'
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );

        $pdo->exec(
            <<<'SQL'
            CREATE TABLE tickets_productos_correo_reglas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                evento VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                mail_account_id BIGINT UNSIGNED NOT NULL,
                enviar_solicitante TINYINT(1) NOT NULL DEFAULT 1,
                enviar_responsables TINYINT(1) NOT NULL DEFAULT 0,
                to_json JSON NULL,
                cc_json JSON NULL,
                bcc_json JSON NULL,
                activo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tickets_productos_correo_reglas_evento (evento),
                KEY idx_tickets_productos_correo_reglas_account (mail_account_id),
                KEY idx_tickets_productos_correo_reglas_activo (activo),
                CONSTRAINT chk_tickets_productos_correo_reglas_evento CHECK (
                    evento IN (
                        'TICKET_CREADO',
                        'PARTIDA_APROBADA',
                        'PARTIDA_RECHAZADA',
                        'TICKET_RESUELTO_TOTAL',
                        'TICKET_RESUELTO_PARCIAL',
                        'TICKET_CANCELADO'
                    )
                ),
                CONSTRAINT chk_tickets_productos_correo_reglas_flags CHECK (
                    enviar_solicitante IN (0, 1)
                    AND enviar_responsables IN (0, 1)
                    AND activo IN (0, 1)
                ),
                CONSTRAINT fk_tickets_productos_correo_reglas_account
                    FOREIGN KEY (mail_account_id) REFERENCES mail_accounts (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tickets_productos_correo_reglas');
        $pdo->exec('DROP TABLE IF EXISTS mail_accounts');
    }
};

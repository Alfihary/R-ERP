<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'notification_push_1_001_create_push_tables';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    endpoint VARCHAR(2048) NOT NULL,
    endpoint_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    p256dh VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_encoding VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'aes128gcm',
    user_agent VARCHAR(500) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_subscriptions_endpoint (endpoint_fingerprint),
    KEY ix_push_subscriptions_usuario_activo (usuario_id, activo, revoked_at),
    CONSTRAINT fk_push_subscriptions_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT ck_push_subscriptions_activo CHECK (activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE notification_push_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notificacion_id BIGINT UNSIGNED NOT NULL,
    push_subscription_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_push_delivery (notificacion_id, push_subscription_id),
    KEY ix_notification_push_delivery_status (status, last_attempt_at),
    CONSTRAINT fk_notification_push_delivery_notification FOREIGN KEY (notificacion_id)
        REFERENCES notificaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_push_delivery_subscription FOREIGN KEY (push_subscription_id)
        REFERENCES push_subscriptions(id) ON DELETE CASCADE,
    CONSTRAINT ck_notification_push_delivery_status CHECK (
        status IN ('pending', 'sent', 'temporary_failure', 'permanent_failure')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE notification_push_deliveries');
        $pdo->exec('DROP TABLE push_subscriptions');
    }
};

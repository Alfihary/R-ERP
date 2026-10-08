<?php
declare(strict_types=1);
use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string { return 'passkeys_mobile_1_001_create_user_passkeys'; }
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE usuario_passkeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    credential_id VARBINARY(1024) NOT NULL,
    credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_handle VARBINARY(64) NOT NULL,
    public_key TEXT NOT NULL,
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rp_id VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usos BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_uso_en DATETIME NULL,
    revocado_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NOT NULL,
    actualizado_por BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_passkey_credential (credential_hash),
    KEY ix_passkey_usuario (usuario_id, revocado_en),
    CONSTRAINT fk_passkey_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_passkey_creador FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_passkey_editor FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT ck_passkey_handle CHECK (OCTET_LENGTH(user_handle) BETWEEN 16 AND 64),
    CONSTRAINT ck_passkey_id CHECK (OCTET_LENGTH(credential_id) BETWEEN 1 AND 1024)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
    public function down(PDO $pdo): void { $pdo->exec('DROP TABLE usuario_passkeys'); }
};

<?php
declare(strict_types=1);
use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string { return 'passkeys_security_2_001_add_credential_name'; }
    public function up(PDO $pdo): void
    {
        $q = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
        $q->execute(['migration' => 'passkeys_mobile_1_001_create_user_passkeys']);
        if ((int) $q->fetchColumn() !== 1) throw new RuntimeException('PASSKEYS-SECURITY-2 requires PASSKEYS-MOBILE-1.');
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_passkeys' AND COLUMN_NAME = 'nombre'")->fetchColumn();
        if ($exists !== 0) throw new RuntimeException('usuario_passkeys.nombre already exists.');
        $pdo->exec("ALTER TABLE usuario_passkeys ADD COLUMN nombre VARCHAR(80) NOT NULL DEFAULT 'Dispositivo registrado' AFTER usuario_id, ADD CONSTRAINT chk_usuario_passkeys_nombre CHECK (CHAR_LENGTH(TRIM(nombre)) BETWEEN 1 AND 80)");
    }
    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE usuario_passkeys DROP COLUMN nombre');
    }
};

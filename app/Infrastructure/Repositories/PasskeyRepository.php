<?php
declare(strict_types=1);
namespace App\Infrastructure\Repositories;

use App\Domain\Auth\PasskeyStore;
use App\Infrastructure\Database\ConnectionProvider;

final class PasskeyRepository implements PasskeyStore
{
    public function __construct(private readonly ConnectionProvider $connection) {}

    public function ready(): bool
    {
        try {
            $this->connection->pdo()->query('SELECT id, nombre FROM usuario_passkeys LIMIT 0');
            return true;
        } catch (\Throwable) { return false; }
    }

    public function hasAny(): bool
    {
        $q = $this->connection->pdo()->query('SELECT 1 FROM usuario_passkeys p JOIN usuarios u ON u.id = p.usuario_id WHERE p.revocado_en IS NULL AND u.activo = 1 AND u.eliminado_en IS NULL AND p.password_version = CONVERT(SHA2(u.password_hash, 256) USING ascii) COLLATE ascii_bin LIMIT 1');
        return $q->fetchColumn() !== false;
    }

    public function forUser(int $userId): array
    {
        $q = $this->connection->pdo()->prepare('SELECT p.* FROM usuario_passkeys p JOIN usuarios u ON u.id = p.usuario_id WHERE p.usuario_id = :id AND p.revocado_en IS NULL AND p.password_version = CONVERT(SHA2(u.password_hash, 256) USING ascii) COLLATE ascii_bin ORDER BY p.ultimo_uso_en DESC, p.id DESC');
        $q->execute(['id' => $userId]);
        return $q->fetchAll();
    }

    public function create(int $userId, array $credential): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id AND activo = 1 AND eliminado_en IS NULL AND SHA2(password_hash, 256) = :version FOR UPDATE');
            $q->execute(['id' => $userId, 'version' => $credential['password_version']]);
            if (!$q->fetchColumn() || count($this->forUser($userId)) >= 10) { throw new \RuntimeException('User changed or credential limit reached.'); }
            $q = $pdo->prepare('INSERT INTO usuario_passkeys (usuario_id, nombre, credential_id, credential_hash, user_handle, public_key, sign_count, rp_id, password_version, creado_por, actualizado_por) VALUES (:uid, :name, :cid, :hash, :handle, :key, :counter, :rp, :version, :creator, :updater)');
            $q->execute(['uid' => $userId, 'name' => $credential['name'], 'cid' => $credential['credential_id'], 'hash' => hash('sha256', $credential['credential_id']), 'handle' => $credential['user_handle'], 'key' => $credential['public_key'], 'counter' => $credential['sign_count'], 'rp' => $credential['rp_id'], 'version' => $credential['password_version'], 'creator' => $userId, 'updater' => $userId]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public function find(string $credentialId): ?array
    {
        $q = $this->connection->pdo()->prepare('SELECT p.*, u.username, u.email FROM usuario_passkeys p JOIN usuarios u ON u.id = p.usuario_id WHERE p.credential_hash = :hash AND p.revocado_en IS NULL AND u.activo = 1 AND u.eliminado_en IS NULL AND p.password_version = CONVERT(SHA2(u.password_hash, 256) USING ascii) COLLATE ascii_bin LIMIT 1');
        $q->execute(['hash' => hash('sha256', $credentialId)]);
        $row = $q->fetch();
        return $row === false ? null : $row;
    }

    public function accept(array $credential, int $counter): bool
    {
        // Atomic comparison protects against simultaneous assertions and revocation.
        $q = $this->connection->pdo()->prepare('UPDATE usuario_passkeys p JOIN usuarios u ON u.id = p.usuario_id SET p.sign_count = :next, p.usos = p.usos + 1, p.ultimo_uso_en = CURRENT_TIMESTAMP, p.actualizado_por = p.usuario_id WHERE p.id = :id AND p.sign_count = :previous AND p.revocado_en IS NULL AND u.activo = 1 AND u.eliminado_en IS NULL AND p.password_version = CONVERT(SHA2(u.password_hash, 256) USING ascii) COLLATE ascii_bin');
        $q->execute(['next' => $counter, 'id' => $credential['id'], 'previous' => $credential['sign_count']]);
        return $q->rowCount() === 1;
    }

    public function revoke(int $userId, int $credentialId): bool
    {
        $q = $this->connection->pdo()->prepare('UPDATE usuario_passkeys SET revocado_en = CURRENT_TIMESTAMP, actualizado_por = :actor WHERE id = :credential AND usuario_id = :user AND revocado_en IS NULL');
        $q->execute(['actor' => $userId, 'credential' => $credentialId, 'user' => $userId]);
        return $q->rowCount() === 1;
    }
}

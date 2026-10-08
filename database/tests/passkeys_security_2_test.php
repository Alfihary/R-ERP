<?php
declare(strict_types=1);
use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if (!str_ends_with($expectedDatabase, '_test') || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) throw new RuntimeException('Disposable test database required.');
        $passed = [];
        $check = static function (bool $ok, string $label) use (&$passed): void { if (!$ok) throw new RuntimeException($label); $passed[] = $label; };
        $column = $pdo->query("SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_passkeys' AND COLUMN_NAME='nombre'")->fetch();
        $check($column !== false && $column['DATA_TYPE'] === 'varchar' && (int) $column['CHARACTER_MAXIMUM_LENGTH'] === 80 && $column['IS_NULLABLE'] === 'NO', 'Credential name column');
        $pdo->beginTransaction();
        try {
            $name = 'passkey_v2_' . bin2hex(random_bytes(5));
            $hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
            $q = $pdo->prepare('INSERT INTO usuarios(username,email,password_hash,activo) VALUES(?,?,?,1)');
            $q->execute([$name, $name . '@example.invalid', $hash]); $uid = (int) $pdo->lastInsertId();
            $q = $pdo->prepare('INSERT INTO usuario_passkeys(usuario_id,nombre,credential_id,credential_hash,user_handle,public_key,rp_id,password_version,creado_por,actualizado_por) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $id = random_bytes(32); $values = [$uid, 'Windows Hello oficina', $id, hash('sha256', $id), random_bytes(32), 'TEST PUBLIC KEY', 'localhost', hash('sha256', $hash), $uid, $uid];
            $q->execute($values); $credentialId = (int) $pdo->lastInsertId();
            $select = $pdo->prepare('SELECT nombre FROM usuario_passkeys WHERE id=? AND usuario_id=? AND revocado_en IS NULL');
            $select->execute([$credentialId, $uid]); $check($select->fetchColumn() === 'Windows Hello oficina', 'Named credential INSERT and SELECT');
            $revoke = $pdo->prepare('UPDATE usuario_passkeys SET revocado_en=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=? AND usuario_id=? AND revocado_en IS NULL');
            $revoke->execute([$uid,$credentialId,$uid]); $check($revoke->rowCount() === 1, 'Owned credential revoked');
            $revoke->execute([$uid,$credentialId,$uid]); $check($revoke->rowCount() === 0, 'Revocation replay rejected');
            $invalid = $values; $invalid[1] = '   '; $invalid[2] = random_bytes(32); $invalid[3] = hash('sha256', $invalid[2]);
            try { $q->execute($invalid); throw new RuntimeException('Blank credential name accepted.'); }
            catch (PDOException $e) { $check((string) $e->getCode() !== '', 'Blank credential name rejected'); }
            $pdo->rollBack();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['passed' => $passed, 'test_data' => 'rolled_back', 'seeds' => 'none'];
    }
};

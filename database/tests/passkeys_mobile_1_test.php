<?php
declare(strict_types=1);
use App\Infrastructure\Database\DatabaseTest;
return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if (!str_ends_with($expectedDatabase, '_test') || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) throw new RuntimeException('Disposable test database required.');
        $passed = [];
        $check = static function (bool $value, string $label) use (&$passed): void { if (!$value) throw new RuntimeException($label); $passed[] = $label; };
        $table = $pdo->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_passkeys'")->fetch();
        $check($table !== false && $table['ENGINE'] === 'InnoDB' && $table['TABLE_COLLATION'] === 'utf8mb4_unicode_ci', 'Table, engine and collation');
        $indexes = $pdo->query('SHOW INDEX FROM usuario_passkeys')->fetchAll();
        $check(count(array_filter($indexes, fn ($r) => $r['Key_name'] === 'uq_passkey_credential' && !$r['Non_unique'])) === 1, 'Unique credential index');
        $check(in_array('ix_passkey_usuario', array_column($indexes, 'Key_name'), true), 'User index');
        $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='usuario_passkeys' AND DELETE_RULE='RESTRICT'")->fetchColumn() === 3, 'Three restrictive foreign keys');
        $pdo->beginTransaction();
        try {
            $name = 'passkey_test_' . bin2hex(random_bytes(5));
            $q = $pdo->prepare('INSERT INTO usuarios(username,email,password_hash,activo) VALUES(?,?,?,1)');
            $passwordHash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
            $q->execute([$name, $name . '@example.invalid', $passwordHash]);
            $uid = (int) $pdo->lastInsertId();
            $q = $pdo->prepare('INSERT INTO usuario_passkeys(usuario_id,credential_id,credential_hash,user_handle,public_key,rp_id,password_version,creado_por,actualizado_por) VALUES(?,?,?,?,?,?,?,?,?)');
            $id = random_bytes(32);
            $valid = [$uid, $id, hash('sha256', $id), random_bytes(32), 'TEST PUBLIC KEY', 'localhost', hash('sha256', $passwordHash), $uid, $uid];
            $q->execute($valid);
            $check($q->rowCount() === 1, 'Valid INSERT');
            $select = $pdo->prepare('SELECT COUNT(*) FROM usuario_passkeys WHERE usuario_id=? AND revocado_en IS NULL');
            $select->execute([$uid]); $check((int) $select->fetchColumn() === 1, 'SELECT verification');
            $invalid = static function (array $values, string $label) use ($q, $check): void {
                try { $q->execute($values); } catch (PDOException $e) { $check(in_array((string) $e->getCode(), ['23000', 'HY000'], true), $label); return; }
                throw new RuntimeException($label . ' was accepted');
            };
            $invalid($valid, 'Duplicate rejected');
            $bad = $valid; $bad[0] = 0; $bad[2] = hash('sha256', random_bytes(32)); $invalid($bad, 'Invalid user FK rejected');
            $bad = $valid; $bad[3] = ''; $bad[2] = hash('sha256', random_bytes(32)); $invalid($bad, 'Empty handle rejected');
            $pdo->rollBack();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['passed' => $passed, 'test_data' => 'rolled_back', 'seeds' => 'none'];
    }
};

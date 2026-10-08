<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('BASE_PATH', dirname(__DIR__));
$config = require BASE_PATH . '/bootstrap/database.php';
$name = $config->get('database.name');
if (!is_string($name) || !str_ends_with($name, '_test') || $config->get('app.env') === 'production') throw new RuntimeException('Only disposable test databases.');
$connection = new App\Infrastructure\Database\ConnectionProvider($config->get('database'));
$pdo = $connection->pdo();
$repo = new App\Infrastructure\Repositories\PasskeyRepository($connection);
$uid = null; $passed = [];
$check = static function (bool $ok, string $label) use (&$passed): void { if (!$ok) throw new RuntimeException($label); $passed[] = $label; };
try {
    $check($repo->ready(), 'Repository ready');
    $check(!$repo->hasAny(), 'No active credentials initially');
    $name = 'pk_' . bin2hex(random_bytes(8)); $hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $q = $pdo->prepare('INSERT INTO usuarios(username,email,password_hash,activo) VALUES(?,?,?,1)'); $q->execute([$name,$name.'@example.invalid',$hash]); $uid = (int)$pdo->lastInsertId();
    $id = random_bytes(32); $row = ['name'=>'Windows Hello pruebas','credential_id'=>$id, 'user_handle'=>random_bytes(32),'public_key'=>'PUBLIC TEST ONLY','sign_count'=>0,'rp_id'=>'localhost','password_version'=>hash('sha256',$hash)];
    $repo->create($uid,$row); $check($repo->hasAny() && count($repo->forUser($uid))===1, 'Persistent enrollment');
    $credential = $repo->find($id); $check($credential !== null, 'Credential lookup');
    $check($repo->accept($credential,1), 'Atomic counter accepted');
    $check(!$repo->accept($credential,2), 'Stale counter rejected');
    $q=$pdo->prepare('UPDATE usuarios SET activo=0 WHERE id=?'); $q->execute([$uid]); $check($repo->find($id)===null,'Disabled account rejected');
    $q=$pdo->prepare('UPDATE usuarios SET activo=1,password_hash=? WHERE id=?'); $q->execute([password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$uid]);
    $check($repo->find($id)===null && $repo->forUser($uid)===[], 'Password change invalidates keys');
    $q->execute([$hash,$uid]); $check($repo->find($id)!==null,'Restore fixture version');
    $check(!$repo->revoke($uid + 999999, (int) $credential['id']), 'Cross-user revocation rejected');
    $check($repo->revoke($uid, (int) $credential['id']), 'Owned revocation accepted');
    $check(!$repo->revoke($uid, (int) $credential['id']), 'Revocation replay rejected');
    $check($repo->find($id)===null && $repo->forUser($uid)===[], 'Revocation enforced');
    echo count($passed)." repository checks passed\n";
} finally {
    if ($uid !== null) { $q=$pdo->prepare('DELETE FROM usuario_passkeys WHERE usuario_id=?'); $q->execute([$uid]); $q=$pdo->prepare('DELETE FROM usuarios WHERE id=?'); $q->execute([$uid]); }
}

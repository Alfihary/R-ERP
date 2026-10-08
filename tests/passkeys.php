<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', getenv('ERP_TEST_ROOT') ?: dirname(__DIR__));
require BASE_PATH . '/bootstrap/autoload.php';
use App\Core\Session;
use App\Domain\Auth\PasskeyService;
use App\Domain\Auth\PasskeyStore;
final class MemoryPasskeys implements PasskeyStore {
    public array $rows = [];
    public bool $enabled = true;
    public function ready(): bool { return $this->enabled; }
    public function hasAny(): bool { return $this->rows !== []; }
    public function forUser(int $id): array { return array_values(array_filter($this->rows, fn ($r) => $r['usuario_id'] === $id)); }
    public function create(int $id, array $row): void { $this->rows[$row['credential_id']] = $row + ['nombre' => $row['name'], 'creado_en' => '2026-09-19 00:00:00', 'ultimo_uso_en' => null, 'usos' => 0, 'id' => 1, 'usuario_id' => $id, 'username' => 'tester', 'email' => 'tester@example.invalid']; }
    public function find(string $id): ?array { return $this->rows[$id] ?? null; }
    public function accept(array $row, int $counter): bool { if (($this->rows[$row['credential_id']]['sign_count'] ?? -1) !== $row['sign_count']) return false; $this->rows[$row['credential_id']]['sign_count'] = $counter; return true; }
    public function revoke(int $userId, int $credentialId): bool { foreach ($this->rows as $key => $row) { if ($row['usuario_id'] === $userId && $row['id'] === $credentialId) { unset($this->rows[$key]); return true; } } return false; }
}
$passed = [];
function check(bool $ok, string $label): void { global $passed; if (!$ok) throw new RuntimeException($label); $passed[] = $label; }
function rejects(callable $f, string $label): void { try { $f(); } catch (Throwable) { check(true, $label); return; } check(false, $label); }
function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function head(int $major, int $n): string { return $n < 24 ? chr(($major << 5) | $n) : ($n < 256 ? chr(($major << 5) | 24) . chr($n) : chr(($major << 5) | 25) . pack('n', $n)); }
function bytes(string $s): string { return head(2, strlen($s)) . $s; }
function txt(string $s): string { return head(3, strlen($s)) . $s; }
$session = new Session(['name' => 'gr_passkey_test']); $session->start();
$store = new MemoryPasskeys(); $service = new PasskeyService($session, $store, 'http://localhost:8000');
$user = ['user_id' => 1, 'username' => 'tester', 'email' => 'tester@example.invalid'];
try {
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => getenv('OPENSSL_CONF')]);
    if (!$private) throw new RuntimeException('OpenSSL test key failed.');
    $key = openssl_pkey_get_details($private); $id = random_bytes(32);
    $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21" . bytes($key['ec']['x']) . "\x22" . bytes($key['ec']['y']);
    $register = static function (int $flags = 0x45) use ($service, $user, $id, $cose): array {
        $options = $service->registerOptions($user, str_repeat('a', 64));
        check($options->publicKey->authenticatorSelection->residentKey === 'required', 'Discoverable key requested');
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => b64($options->publicKey->challenge->getBinaryString()), 'origin' => 'http://localhost:8000']);
        $data = hash('sha256', 'localhost', true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id . $cose;
        return ['rawId' => b64($id), 'clientDataJSON' => b64($client), 'attestationObject' => b64("\xa3" . txt('fmt') . txt('none') . txt('attStmt') . "\xa0" . txt('authData') . bytes($data))];
    };
    $bad = $register(0x41); rejects(fn () => $service->register($user, 'Mi equipo', $bad), 'Enrollment requires UV');
    $valid = $register(); $service->register($user, 'Mi equipo', $valid); check(count($service->credentials(1)) === 1, 'Registered key persisted');
    rejects(fn () => $service->register($user, 'Mi equipo', $valid), 'Registration replay denied');
    $session->invalidate(); $session->start();
    $assertion = static function (int $counter = 1, int $flags = 5, string $origin = 'http://localhost:8000', string $rp = 'localhost') use ($service, $store, $id, $private): array {
        $options = $service->loginOptions();
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => b64($options->publicKey->challenge->getBinaryString()), 'origin' => $origin]);
        $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
        openssl_sign($data . hash('sha256', $client, true), $signature, $private, OPENSSL_ALGO_SHA256);
        return ['rawId' => b64($id), 'clientDataJSON' => b64($client), 'authenticatorData' => b64($data), 'signature' => b64($signature), 'userHandle' => b64($store->rows[$id]['user_handle'] ?? random_bytes(32))];
    };
    foreach (['Missing UV' => [1, 1], 'Missing UP' => [1, 4], 'Wrong origin' => [1, 5, 'http://localhost:9000'], 'Wrong RP' => [1, 5, 'http://localhost:8000', 'evil.example']] as $label => $args) {
        $bad = $assertion(...$args); rejects(fn () => $service->login($bad), $label);
    }
    foreach (['signature', 'rawId', 'userHandle'] as $field) { $bad = $assertion(); $bad[$field] = b64(random_bytes(32)); rejects(fn () => $service->login($bad), 'Invalid ' . $field); }
    $bad = $assertion(); $pending = $session->get('passkey_challenge'); $pending['expires'] = time() - 1; $session->put('passkey_challenge', $pending);
    rejects(fn () => $service->login($bad), 'Expired challenge');
    $valid = $assertion(); check($service->login($valid)['user_id'] === 1, 'Signed login after logout');
    rejects(fn () => $service->login($valid), 'Login replay denied');
    $bad = $assertion(); rejects(fn () => $service->login($bad), 'Counter replay denied');
    $valid = $assertion(2); check($service->login($valid)['user_id'] === 1, 'Next signed login');
    $bad = $assertion(3); check($service->revoke(1, 1), 'Owned credential revoked'); rejects(fn () => $service->login($bad), 'Revocation cancels challenge');
    $bad = $assertion(3); rejects(fn () => $service->login($bad), 'Revoked key denied');
    $store->enabled = false; rejects(fn () => $service->loginOptions(), 'Missing database disabled');
    check(!(new PasskeyService($session, $store, 'http://remote.example'))->ready(), 'Insecure remote origin disabled');
} finally { $session->invalidate(); }
echo count($passed) . " passkey checks passed\n";

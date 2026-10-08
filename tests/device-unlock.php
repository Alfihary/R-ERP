<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Pure session/cryptographic tests: no database, accounts or HTTP test backdoors.
$root = dirname(__DIR__);
$fallback = getenv('ERP_TEST_SOURCE') ?: $root;
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root, $fallback): void {
    if (!str_starts_with($class, 'App\\')) { return; }
    $relative = '/' . str_replace('\\', '/', lcfirst(substr($class, 0, 3)) . substr($class, 3)) . '.php';
    foreach (array_unique([$root, $fallback]) as $base) {
        if (is_file($base . $relative)) { require $base . $relative; return; }
    }
});

use App\Core\Session;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\DeviceUnlockService;
use App\Domain\Auth\SessionLockService;
use App\Http\Middlewares\AuthMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\UserRepository;

$session = new Session(['name' => 'gr_device_test']);
$session->start();
$lock = new SessionLockService($session);
$device = new DeviceUnlockService($session, $lock, 'http://localhost:8000');
$user = ['user_id' => 1, 'username' => 'test-only', 'email' => 'test@example.invalid'];
$session->put('auth_user', $user);
$auth = new AuthService(new UserRepository(new ConnectionProvider([])), $session);
$passed = [];
function check(bool $value, string $label): void { global $passed; if (!$value) throw new RuntimeException($label); $passed[] = $label; }
function rejects(callable $fn, string $label): void { try { $fn(); } catch (Throwable) { check(true, $label); return; } check(false, $label); }
function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function cborHead(int $major, int $length): string {
    return $length < 24 ? chr(($major << 5) | $length)
        : ($length < 256 ? chr(($major << 5) | 24) . chr($length) : chr(($major << 5) | 25) . pack('n', $length));
}
function bytes(string $value): string { return cborHead(2, strlen($value)) . $value; }
function textString(string $value): string { return cborHead(3, strlen($value)) . $value; }

try {
    check($device->available(), 'Configured localhost accepted');
    check((new DeviceUnlockService($session, $lock, 'http://127.0.0.1:8000'))->available(), 'IPv4 loopback accepted');
    check(!(new DeviceUnlockService($session, $lock, 'http://erp.example'))->available(), 'Non-HTTPS remote origin rejected');
    $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    if (getenv('OPENSSL_CONF')) { $keyOptions['config'] = getenv('OPENSSL_CONF'); }
    $private = openssl_pkey_new($keyOptions);
    if ($private === false) throw new RuntimeException('Configure OPENSSL_CONF for test key generation.');
    $key = openssl_pkey_get_details($private);
    $id = random_bytes(32);
    $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21" . bytes($key['ec']['x']) . "\x22" . bytes($key['ec']['y']);
    $registration = static function (int $flags = 0x45, string $origin = 'http://localhost:8000') use ($device, $user, $id, $cose): array {
        $options = $device->options($user, 'register');
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => b64($options->publicKey->challenge->getBinaryString()), 'origin' => $origin]);
        $data = hash('sha256', 'localhost', true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id . $cose;
        $attestation = "\xa3" . textString('fmt') . textString('none') . textString('attStmt') . "\xa0" . textString('authData') . bytes($data);
        return ['rawId' => b64($id), 'clientDataJSON' => b64($client), 'attestationObject' => b64($attestation)];
    };
    $invalid = $registration(0x41);
    rejects(fn () => $device->complete($user, 'register', $invalid), 'Enrollment requires user verification');
    check(!$lock->enabled(), 'Failed enrollment does not enable protection');
    $valid = $registration();
    $device->complete($user, 'register', $valid);
    check($lock->enabled() && !$lock->locked(), 'Signed-format registration accepted');
    rejects(fn () => $device->complete($user, 'register', $valid), 'Registration cannot replay');
    $lock->lock();
    $middleware = new AuthMiddleware($auth);
    check($middleware->process(new Request('GET', '/app'), fn () => Response::html('PRIVATE'))->status() === 302, 'Locked session cannot read private route');
    check($middleware->process(new Request('POST', '/productos'), fn () => Response::html('MUTATED'))->status() === 302, 'Locked session cannot write private route');
    check((new AuthMiddleware($auth, true, false))->process(new Request('GET', '/desbloquear'), fn () => Response::html('UNLOCK'))->status() === 200, 'Unlock page remains accessible');
    $lock->touch();
    check($lock->locked(), 'Activity cannot unlock a locked session');

    $assertion = static function (int $count = 1, int $flags = 5, string $origin = 'http://localhost:8000', string $rp = 'localhost') use ($device, $user, $id, $private): array {
        $options = $device->options($user, 'unlock');
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => b64($options->publicKey->challenge->getBinaryString()), 'origin' => $origin]);
        $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $count);
        openssl_sign($data . hash('sha256', $client, true), $signature, $private, OPENSSL_ALGO_SHA256);
        return ['rawId' => b64($id), 'clientDataJSON' => b64($client), 'authenticatorData' => b64($data), 'signature' => b64($signature)];
    };
    foreach ([
        'User verification flag required' => [1, 1],
        'User presence flag required' => [1, 4],
        'Different origin rejected' => [1, 5, 'http://localhost:9000'],
        'Different relying party rejected' => [1, 5, 'http://localhost:8000', 'other.example'],
    ] as $label => $args) {
        $invalid = $assertion(...$args);
        rejects(fn () => $device->complete($user, 'unlock', $invalid), $label);
        check($lock->locked(), $label . ': remains locked');
    }
    $invalid = $assertion(); $invalid['signature'] = b64(random_bytes(64));
    rejects(fn () => $device->complete($user, 'unlock', $invalid), 'Forged signature rejected');
    $invalid = $assertion(); $invalid['rawId'] = b64(random_bytes(32));
    rejects(fn () => $device->complete($user, 'unlock', $invalid), 'Wrong credential rejected');
    $invalid = $assertion(); $pending = $session->get('device_challenge'); $pending['expires'] = time() - 1; $session->put('device_challenge', $pending);
    rejects(fn () => $device->complete($user, 'unlock', $invalid), 'Expired challenge rejected');
    $valid = $assertion();
    $device->complete($user, 'unlock', $valid);
    check(!$lock->locked(), 'Valid signature unlocks session');
    rejects(fn () => $device->complete($user, 'unlock', $valid), 'Assertion cannot replay');
    $lock->lock(); $invalid = $assertion(1);
    rejects(fn () => $device->complete($user, 'unlock', $invalid), 'Repeated signature counter rejected');
    $state = $lock->state(); $state['locked'] = false; $state['last_activity'] = time() - 301; $session->put('device_unlock', $state);
    check($lock->locked(), 'Inactivity enforced without JavaScript');
    $state['created_at'] = time() - 28801; $session->put('device_unlock', $state);
    rejects(fn () => $device->options($user, 'unlock'), 'Eight-hour expiry enforced');
    $lock->reset();
    check(!$lock->enabled(), 'Protection reset after session ends');
    for ($i = 0; $i < 10; $i++) $device->rateLimit();
    rejects(fn () => $device->rateLimit(), 'Rate limit enforced');
    $session->invalidate();
    echo json_encode(['passed' => count($passed), 'checks' => $passed], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    $session->invalidate();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

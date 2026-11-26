<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require_once $root . '/bootstrap/autoload.php';

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\WriteConnectionProvider;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

$keys = [
    'APP_DB_HOST',
    'APP_DB_PORT',
    'APP_DB_NAME',
    'APP_DB_CHARSET',
    'APP_DB_COLLATION',
    'APP_DB_USER',
    'APP_DB_PASSWORD',
    'APP_DB_READ_USER',
    'APP_DB_READ_PASSWORD',
    'APP_DB_WRITE_USER',
    'APP_DB_WRITE_PASSWORD',
];

$snapshotEnv = static function (array $keys): array {
    $snapshot = [];

    foreach ($keys as $key) {
        $environmentValue = getenv($key);
        $snapshot[$key] = [
            'environment' => [
                'exists' => $environmentValue !== false,
                'value' => $environmentValue === false ? null : (string) $environmentValue,
            ],
            'env' => [
                'exists' => array_key_exists($key, $_ENV),
                'value' => array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null,
            ],
            'server' => [
                'exists' => array_key_exists($key, $_SERVER),
                'value' => array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null,
            ],
        ];
    }

    return $snapshot;
};

$restoreEnv = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        if ($sources['environment']['exists']) {
            putenv($key . '=' . $sources['environment']['value']);
        } else {
            putenv($key);
        }

        if ($sources['env']['exists']) {
            $_ENV[$key] = $sources['env']['value'];
        } else {
            unset($_ENV[$key]);
        }

        if ($sources['server']['exists']) {
            $_SERVER[$key] = $sources['server']['value'];
        } else {
            unset($_SERVER[$key]);
        }
    }
};

$assertEnvMatchesSnapshot = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        $environmentValue = getenv($key);
        if (($environmentValue !== false) !== $sources['environment']['exists']) {
            throw new RuntimeException('RW3 environment restoration failed for ' . $key . '.');
        }
        if ($sources['environment']['exists']
            && (string) $environmentValue !== $sources['environment']['value']) {
            throw new RuntimeException('RW3 environment value restoration failed for ' . $key . '.');
        }

        $envExists = array_key_exists($key, $_ENV);
        if ($envExists !== $sources['env']['exists']) {
            throw new RuntimeException('RW3 $_ENV restoration failed for ' . $key . '.');
        }
        if ($envExists && (string) $_ENV[$key] !== $sources['env']['value']) {
            throw new RuntimeException('RW3 $_ENV value restoration failed for ' . $key . '.');
        }

        $serverExists = array_key_exists($key, $_SERVER);
        if ($serverExists !== $sources['server']['exists']) {
            throw new RuntimeException('RW3 $_SERVER restoration failed for ' . $key . '.');
        }
        if ($serverExists && (string) $_SERVER[$key] !== $sources['server']['value']) {
            throw new RuntimeException('RW3 $_SERVER value restoration failed for ' . $key . '.');
        }
    }
};

$set = static function (string $key, ?string $value): void {
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

$readSource = static function (string $relativePath) use ($root): string {
    $path = $root . '/' . $relativePath;
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('RW3 source file could not be read: ' . $relativePath . '.');
    }

    return $source;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$methodBody = static function (string $source, string $method): string {
    $offset = strpos($source, 'function ' . $method . '(');
    if ($offset === false) {
        throw new RuntimeException('RW3 controller method is missing: ' . $method . '.');
    }

    $open = strpos($source, '{', $offset);
    if ($open === false) {
        throw new RuntimeException('RW3 controller method has no body: ' . $method . '.');
    }

    $depth = 0;
    $length = strlen($source);
    for ($index = $open; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open, $index - $open + 1);
            }
        }
    }

    throw new RuntimeException('RW3 controller method body is incomplete: ' . $method . '.');
};

$snapshot = $snapshotEnv($keys);

foreach ($keys as $key) {
    $set($key, null);
}

try {
    $bootstrap = $readSource('bootstrap/app.php');
    $controller = $readSource('app/Http/Controllers/ProductRequestTicketController.php');
    $service = $readSource('app/Domain/Tickets/ProductRequestTicketService.php');
    $repository = $readSource('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');
    $outboxRepository = $readSource('app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php');
    $mailConfigurationRepository = $readSource('app/Infrastructure/Repositories/MailConfigurationRepository.php');

    $assert(str_contains($repository, 'use App\\Infrastructure\\Database\\WriteConnectionProvider;'), 'RW3-02 repository WRITE provider import missing.');
    $assert(str_contains($repository, 'ConnectionProvider|WriteConnectionProvider'), 'RW3-06 mixed repository does not accept explicit WRITE dependency.');
    $assert(str_contains($service, '$this->tickets->beginTransaction()'), 'RW3-01 service transaction owner contract changed.');
    $assert(str_contains($service, '$this->tickets->commit($ownsTransaction)'), 'RW3-12/13 service commit contract changed.');
    $assert(str_contains($service, '$this->tickets->rollBack($ownsTransaction)'), 'RW3-12/13 service rollback contract changed.');

    $assert(str_contains($controller, 'private readonly ?ProductRequestTicketService $writeTickets = null'), 'RW3-01 controller lacks lazy WRITE service dependency.');
    $assert(substr_count($controller, '$this->writeTickets()->') >= 6, 'RW3-01 write controller actions are not routed to WRITE service.');
    $assert(str_contains($controller, '$this->tickets->obtenerTicket('), 'RW3-04 GET controller wiring lost legacy/read service.');
    $assert(str_contains($controller, '$this->tickets->descargarAdjunto('), 'RW3-04 GET attachment wiring lost legacy/read service.');
    $assert(str_contains($controller, 'La escritura de tickets no está disponible.'), 'RW3-05 missing WRITE service has no controlled failure.');

    $routingSpy = new class {
        /** @var list<array{action:string,service:string}> */
        public array $calls = [];

        public function dispatch(string $action, string $service): void
        {
            $this->calls[] = ['action' => $action, 'service' => $service];
        }
    };
    $mutatingActions = ['store', 'approveLine', 'rejectLine', 'comment', 'attachment', 'cancel'];
    $readActions = ['index', 'create', 'show', 'downloadAttachment'];
    foreach ($mutatingActions as $action) {
        $body = $methodBody($controller, $action);
        $service = str_contains($body, '$this->writeTickets()->') ? 'write' : 'legacy';
        $routingSpy->dispatch($action, $service);
        $assert($service === 'write', 'RW3 routing regression: mutator uses legacy service: ' . $action . '.');
    }
    foreach ($readActions as $action) {
        $body = $methodBody($controller, $action);
        $service = str_contains($body, '$this->writeTickets()->') ? 'write' : 'legacy';
        $routingSpy->dispatch($action, $service);
        $assert($service === 'legacy', 'RW3 routing regression: GET uses WRITE service: ' . $action . '.');
    }
    $assert(count($routingSpy->calls) === count($mutatingActions) + count($readActions), 'RW3 routing spy did not exercise all controller actions.');

    $assert(str_contains($bootstrap, '$productRequestTickets = new ProductRequestTicketService('), 'RW3-11 legacy ticket service construction missing.');
    $assert(str_contains($bootstrap, '$productRequestTicketsWrite = new ProductRequestTicketService('), 'RW3-01 WRITE ticket service construction missing.');
    $assert(str_contains($bootstrap, 'new ProductRequestTicketRepository($writeConnectionProvider)'), 'RW3-06 ticket repository is not wired to WRITE provider.');
    $assert(str_contains($bootstrap, 'new ProductTicketEmailOutboxRepository($writeConnectionProvider)'), 'RW3-14 ticket outbox is not wired to shared WRITE provider.');
    $assert(str_contains($bootstrap, 'new MailConfigurationRepository($writeConnectionProvider)'), 'RW3-09 mail configuration is not wired to shared WRITE provider.');
    $assert(!preg_match('/\$(?:read|write)ConnectionProvider\s*->\s*pdo\s*\(/', $bootstrap), 'RW3-03 bootstrap eagerly opens a semantic provider.');
    $assert(!str_contains($bootstrap, 'new PDO'), 'RW3-03 bootstrap constructs PDO directly.');
    $assert(str_contains($bootstrap, '$productRequestTicketsWrite'), 'RW3-01 WRITE service is not passed to the controller.');

    foreach (['findTicketByIdForUpdate', 'findPartidaByIdForUpdate', 'findSeriesForUpdate'] as $method) {
        $assert(str_contains($repository, 'function ' . $method), 'RW3-07 missing FOR UPDATE method: ' . $method . '.');
    }
    $assert(substr_count($repository, 'FOR UPDATE') >= 3, 'RW3-07 expected ticket lock SQL is missing.');
    $assert(str_contains($repository, 'tickets_productos_eventos'), 'RW3-09 ticket events are not present in the migrated repository.');
    $assert(str_contains($repository, 'documentos_folios'), 'RW3-08 folio writes are not present in the migrated repository.');
    $assert(str_contains($repository, 'series_documentales'), 'RW3-08 folio series are not present in the migrated repository.');

    $assert(str_contains($outboxRepository, 'ConnectionProvider|WriteConnectionProvider|PDO'), 'RW3-14 outbox repository does not accept WRITE provider.');
    $assert(str_contains($outboxRepository, '$this->connection instanceof WriteConnectionProvider'), 'RW3-14 outbox does not resolve the WRITE provider.');
    $assert(str_contains($mailConfigurationRepository, 'ConnectionProvider|WriteConnectionProvider'), 'RW3-09 mail configuration repository does not accept WRITE provider.');

    $untouchedConsumerFiles = [
        'app/Domain/Mail/MailOutboxActionService.php',
        'app/Infrastructure/Repositories/MailOutboxActionRepository.php',
        'app/Infrastructure/Repositories/MailOutboxQueryRepository.php',
        'database/tickets-productos-partidas-estados-correo-procesador.php',
    ];
    foreach ($untouchedConsumerFiles as $relativePath) {
        $source = $readSource($relativePath);
        $assert(!str_contains($source, 'ReadConnectionProvider'), 'RW3-17 unexpected READ migration: ' . $relativePath . '.');
        $assert(!str_contains($source, 'WriteConnectionProvider'), 'RW3-17 unexpected WRITE migration: ' . $relativePath . '.');
    }

    $legacyProvider = new ConnectionProvider([
        'host' => 'invalid-rw3-host',
        'port' => 3306,
        'name' => 'rw3_no_connection',
        'username' => 'rw3_legacy_fixture',
        'password' => 'rw3_legacy_password_fixture',
    ]);
    $assert($legacyProvider instanceof ConnectionProvider, 'RW3-11 legacy provider construction failed.');

    $writeProvider = new WriteConnectionProvider([
        'common' => [
            'host' => 'invalid-rw3-host',
            'port' => 3306,
            'database' => 'rw3_no_connection',
        ],
        'read' => [
            'user' => 'rw3_read_fixture',
            'password' => 'rw3_read_password_fixture',
        ],
        'write' => [
            'user' => '',
            'password' => '',
        ],
    ]);
    try {
        $writeProvider->pdo();
        throw new RuntimeException('RW3-05 WRITE provider unexpectedly connected without credentials.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Write database credentials are not configured.', 'RW3-05 WRITE provider fail-closed message changed.');
    }

    $ticketRepository = new ProductRequestTicketRepository($writeProvider);
    $mailConfigurationRepository = new MailConfigurationRepository($writeProvider);
    $outboxRepository = new ProductTicketEmailOutboxRepository($writeProvider);
    foreach ([
        $ticketRepository,
        $mailConfigurationRepository,
        $outboxRepository,
    ] as $index => $repositoryInstance) {
        $property = new ReflectionProperty($repositoryInstance, 'connection');
        $property->setAccessible(true);
        $assert($property->getValue($repositoryInstance) === $writeProvider, 'RW3 shared WRITE provider identity failed at dependency ' . $index . '.');
    }

    $pdo = new PDO('sqlite::memory:');
    $pdoProperty = new ReflectionProperty(WriteConnectionProvider::class, 'pdo');
    $pdoProperty->setAccessible(true);
    $pdoProperty->setValue($writeProvider, $pdo);
    $assert($writeProvider->pdo() === $pdo, 'RW3 provider did not return the injected PDO instance.');
    $assert($writeProvider->pdo() === $writeProvider->pdo(), 'RW3 provider did not preserve PDO identity across calls.');

    $ownsTransaction = $ticketRepository->beginTransaction();
    $ticketRepository->rollBack($ownsTransaction);
    $mailConfigurationRepository->transaction(static fn (): bool => true);
    $outboxPdoMethod = new ReflectionMethod($outboxRepository, 'pdo');
    $outboxPdoMethod->setAccessible(true);
    $assert($outboxPdoMethod->invoke($outboxRepository) === $pdo, 'RW3 outbox did not resolve the shared PDO identity.');

    $set('APP_DB_NAME', 'r_erp_tickets_qa_2f');
    $set('APP_DB_USER', 'r_erp_tickets_qa_setup');
    $assert(!str_contains($bootstrap, "Env::get('APP_DB_WRITE_"), 'RW3-10 bootstrap directly resolves WRITE environment.');
    $assert(!str_contains($repository, 'getenv('), 'RW3-15 repository routes by environment instead of provider.');

    echo "RW_3_DB_FREE_TESTS=PASS\n";
} finally {
    $restoreEnv($snapshot);
}

$assertEnvMatchesSnapshot($snapshot);

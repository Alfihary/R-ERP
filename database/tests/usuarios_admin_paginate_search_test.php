<?php

declare(strict_types=1);

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\UserRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/bootstrap/autoload.php';

$config = require BASE_PATH . '/bootstrap/database.php';
$environment = strtolower((string) $config->get('app.env', 'production'));
$database = $config->get('database', []);

if ($environment === 'production' || !is_array($database)
    || (string) ($database['name'] ?? '') !== 'r_erp_db_core_0_test') {
    throw new RuntimeException('The directed test requires the local test database.');
}

$pdo = Connection::create($database);
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'r_erp_db_core_0_test') {
    throw new RuntimeException('The active database does not match the confirmed test database.');
}

$repository = new UserRepository(new ConnectionProvider($database));
$cases = [
    'SEARCH_NO_FILTER' => [],
    'SEARCH_Q_ONLY' => ['q' => 'qa.http.created'],
    'SEARCH_Q_WITH_STATUS' => ['q' => 'qa.http.created', 'status' => 'active'],
    'SEARCH_Q_WITH_COMPANY' => ['q' => 'qa.http.created', 'company_id' => 1],
    'SEARCH_Q_WITH_WAREHOUSE' => ['q' => 'qa.http.created', 'warehouse_id' => 1],
    'SEARCH_Q_WITH_ROLE' => ['q' => 'qa.http.created', 'role_id' => 1],
];

foreach ($cases as $name => $filters) {
    $repository->paginateAdmin($filters);
    echo $name . "=PASS\n";
}

echo "HY093_AFTER_FIX=false\n";

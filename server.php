<?php
declare(strict_types=1);

// Router exclusivo para el servidor de desarrollo integrado de PHP.
$public = realpath(__DIR__ . '/public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$decoded = is_string($path) ? rawurldecode($path) : '/';
$asset = $public === false ? false : realpath($public . DIRECTORY_SEPARATOR . ltrim($decoded, '/'));

if ($public !== false
    && $decoded !== '/'
    && $asset !== false
    && str_starts_with($asset, $public . DIRECTORY_SEPARATOR)
    && is_file($asset)
) {
    return false;
}

require __DIR__ . '/public/index.php';

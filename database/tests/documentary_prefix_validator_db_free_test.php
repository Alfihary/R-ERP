<?php

declare(strict_types=1);

use App\Domain\Folios\DocumentaryPrefixValidator;
use App\Domain\Folios\FolioValidationException;

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/bootstrap/autoload.php';

$validator = new DocumentaryPrefixValidator();

$assertAccepted = static function (string $case, callable $callback, mixed $expected = null): void {
    try {
        $actual = $callback();
    } catch (FolioValidationException $exception) {
        throw new RuntimeException($case . ' was rejected: ' . $exception->getMessage());
    }

    if ($expected !== null && $actual !== $expected) {
        throw new RuntimeException($case . ' returned an unexpected value.');
    }
};

$assertRejected = static function (string $case, callable $callback): void {
    try {
        $callback();
    } catch (FolioValidationException) {
        return;
    }

    throw new RuntimeException($case . ' was accepted unexpectedly.');
};

$acceptedPrefixes = [
    'AB',
    'ABC',
    'ABCDE',
];

foreach ($acceptedPrefixes as $prefix) {
    $assertAccepted('prefix_' . $prefix, fn (): string => $validator->validatePrefix($prefix), $prefix);
}

foreach ([null, '', ' ', ' AB', 'A', 'ABCDEF', 'abc', 'AB-CD', 'AB_CD', 'AB.C', 'AB CD', 'ÁB'] as $prefix) {
    $assertRejected('invalid_prefix_' . $prefix, fn (): string => $validator->validatePrefix($prefix));
}

foreach ([1, 10, 999999] as $consecutive) {
    $assertAccepted(
        'consecutive_' . $consecutive,
        fn (): int => $validator->validateConsecutive($consecutive),
        $consecutive
    );
}

foreach ([0, 1000000, '10', 1.5, null] as $consecutive) {
    $assertRejected(
        'invalid_consecutive_' . (is_scalar($consecutive) ? (string) $consecutive : get_debug_type($consecutive)),
        fn (): int => $validator->validateConsecutive($consecutive)
    );
}

$assertAccepted(
    'folio_GU_10',
    fn (): string => $validator->formatFolio('GU', 10),
    'GU-000010'
);
$assertAccepted(
    'folio_ALM05_999999',
    fn (): string => $validator->formatFolio('ALM05', 999999),
    'ALM05-999999'
);

echo "DB_FREE_TESTS=PASS\n";

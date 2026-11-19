<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDOException;

final class GlobalPrefixDuplicateClassifier
{
    private const EXPECTED_INDEX_REFERENCES = [
        "'uq_series_documentales_prefijo_global'",
        '`uq_series_documentales_prefijo_global`',
        "'series_documentales.uq_series_documentales_prefijo_global'",
        '`series_documentales.uq_series_documentales_prefijo_global`',
        "'r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global'",
        '`r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global`',
        '`series_documentales`.`uq_series_documentales_prefijo_global`',
        '`r_erp_tickets_qa_2f`.`series_documentales`.`uq_series_documentales_prefijo_global`',
    ];

    public static function isExpected(PDOException $exception): bool
    {
        $info = $exception->errorInfo;
        $message = (string) ($info[2] ?? $exception->getMessage());

        return (string) ($info[0] ?? $exception->getCode()) === '23000'
            && (int) ($info[1] ?? 0) === 1062
            && self::hasExpectedIndexReference($message);
    }

    private static function hasExpectedIndexReference(string $message): bool
    {
        foreach (self::EXPECTED_INDEX_REFERENCES as $reference) {
            $pattern = '/\ADuplicate entry[ \t]+\'([^\'\\\\\r\n\t]*)\'[ \t]+(?i:for[ \t]+key)[ \t]+'
                . preg_quote($reference, '/')
                . '\z/';

            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}

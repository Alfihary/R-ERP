<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require_once dirname(__DIR__, 2) . '/bootstrap/autoload.php';

use App\Infrastructure\Database\GlobalPrefixDuplicateClassifier;

function syntheticPdoException(string $sqlState, int $nativeCode, string $message): PDOException
{
    $exception = new PDOException('synthetic diagnostic fixture');
    $exception->errorInfo = [$sqlState, $nativeCode, $message];
    return $exception;
}

$cases = [
    ['single-quoted-global', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", true],
    ['backtick-global', '23000', 1062, "Duplicate entry 'F67' for key `uq_series_documentales_prefijo_global`", true],
    ['table-qualified-single', '23000', 1062, "Duplicate entry 'F67' for key 'series_documentales.uq_series_documentales_prefijo_global'", true],
    ['table-qualified-backtick-whole', '23000', 1062, "Duplicate entry 'F67' for key `series_documentales.uq_series_documentales_prefijo_global`", true],
    ['schema-qualified-single', '23000', 1062, "Duplicate entry 'F67' for key 'r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global'", true],
    ['schema-qualified-backtick-whole', '23000', 1062, "Duplicate entry 'F67' for key `r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global`", true],
    ['table-qualified-backticks', '23000', 1062, "Duplicate entry 'F67' for key `series_documentales`.`uq_series_documentales_prefijo_global`", true],
    ['schema-qualified-backticks', '23000', 1062, "Duplicate entry 'F67' for key `r_erp_tickets_qa_2f`.`series_documentales`.`uq_series_documentales_prefijo_global`", true],
    ['scope-index', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_scope'", false],
    ['other-unique', '23000', 1062, "Duplicate entry 'F67' for key 'uq_other_index'", false],
    ['suffix', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global_extra'", false],
    ['prefix', '23000', 1062, "Duplicate entry 'F67' for key 'xuq_series_documentales_prefijo_global'", false],
    ['wrong-sqlstate', '23505', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", false],
    ['wrong-native-code', '23000', 1451, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", false],
    ['missing-index', '23000', 1062, "Duplicate entry 'F67'", false],
    ['partial-name', '23000', 1062, "Duplicate entry 'F67' for key 'prefijo_global'", false],
    ['malformed-delimiter', '23000', 1062, "Duplicate entry 'F67' for key `uq_series_documentales_prefijo_global'", false],
    ['unauthorized-qualification', '23000', 1062, "Duplicate entry 'F67' for key 'other_schema.series_documentales.uq_series_documentales_prefijo_global'", false],
    ['case-variant-index-name', '23000', 1062, "Duplicate entry 'F67' for key 'UQ_SERIES_DOCUMENTALES_PREFIJO_GLOBAL'", false],
    ['two-global-references', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'; also for key 'uq_series_documentales_prefijo_global'", false],
    ['other-then-global', '23000', 1062, "Duplicate entry 'F67' for key 'uq_other_index'; also for key 'uq_series_documentales_prefijo_global'", false],
    ['global-then-other', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'; also for key 'uq_other_index'", false],
    ['arbitrary-for-key-text', '23000', 1062, "Notice: for key 'uq_series_documentales_prefijo_global'", false],
    ['missing-duplicate-entry-structure', '23000', 1062, "Key violation for key 'uq_series_documentales_prefijo_global'", false],
    ['text-after-reference', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global' unexpected", false],
    ['global-inside-value-real-other-key', '23000', 1062, "Duplicate entry 'value for key \'uq_series_documentales_prefijo_global\'' for key 'uq_other_index'", false],
    ['other-name-inside-value-authentic-key', '23000', 1062, "Duplicate entry 'value uq_other_index' for key 'uq_series_documentales_prefijo_global'", true],
    ['incomplete-quotes', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global", false],
    ['suffix-again', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global_extra'", false],
    ['unauthorized-qualification-again', '23000', 1062, "Duplicate entry 'F67' for key 'other_schema.series_documentales.uq_series_documentales_prefijo_global'", false],
    ['f97-reproducible-boundary', '23000', 1062, "Duplicate entry 'value for key 'uq_series_documentales_prefijo_global'", false],
    ['value-without-closing-quote', '23000', 1062, "Duplicate entry 'value for key 'uq_series_documentales_prefijo_global'", false],
    ['empty-value', '23000', 1062, "Duplicate entry '' for key 'uq_series_documentales_prefijo_global'", true],
    ['alphanumeric-value', '23000', 1062, "Duplicate entry 'F67A9' for key 'uq_series_documentales_prefijo_global'", true],
    ['spaced-value', '23000', 1062, "Duplicate entry 'value with spaces' for key 'uq_series_documentales_prefijo_global'", true],
    ['for-key-inside-malformed-value', '23000', 1062, "Duplicate entry 'value for key 'uq_series_documentales_prefijo_global'", false],
    ['global-name-in-value-other-key', '23000', 1062, "Duplicate entry 'value uq_series_documentales_prefijo_global' for key 'uq_other_index'", false],
    ['delimited-value-global-key', '23000', 1062, "Duplicate entry 'value for key marker' for key 'uq_series_documentales_prefijo_global'", true],
    ['delimited-value-scope-key', '23000', 1062, "Duplicate entry 'value for key marker' for key 'uq_series_documentales_scope'", false],
    ['extra-after-reference', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global' trailing", false],
    ['nested-malformed-delimiters', '23000', 1062, "Duplicate entry 'value '' for key 'uq_series_documentales_prefijo_global'", false],
    ['multiple-for-key-segments', '23000', 1062, "Duplicate entry 'value' for key 'uq_series_documentales_prefijo_global' for key 'uq_other_index'", false],
    ['wrong-native-code-again', '23000', 1451, "Duplicate entry 'value' for key 'uq_series_documentales_prefijo_global'", false],
    ['wrong-sqlstate-again', '23505', 1062, "Duplicate entry 'value' for key 'uq_series_documentales_prefijo_global'", false],
    ['partial-global-identifier', '23000', 1062, "Duplicate entry 'value' for key 'uq_series_documentales_prefijo_glo'", false],
    ['lf-initial', '23000', 1062, "\nDuplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", false],
    ['lf-final', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'\n", false],
    ['cr-initial', '23000', 1062, "\rDuplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", false],
    ['cr-final', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'\r", false],
    ['crlf-initial', '23000', 1062, "\r\nDuplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", false],
    ['crlf-final', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'\r\n", false],
    ['lf-inside-value', '23000', 1062, "Duplicate entry 'value\npart' for key 'uq_series_documentales_prefijo_global'", false],
    ['cr-inside-value', '23000', 1062, "Duplicate entry 'value\rpart' for key 'uq_series_documentales_prefijo_global'", false],
    ['spaces-at-extremes', '23000', 1062, " Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global' ", false],
    ['tabs-at-extremes', '23000', 1062, "\tDuplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'\t", false],
    ['tab-inside-value', '23000', 1062, "Duplicate entry 'value\tpart' for key 'uq_series_documentales_prefijo_global'", false],
    ['two-lf-final', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'\n\n", false],
    ['canonical-without-external-whitespace', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_prefijo_global'", true],
    ['f97-repro-again', '23000', 1062, "Duplicate entry 'value for key 'uq_series_documentales_prefijo_global'", false],
    ['unauthorized-index-final', '23000', 1062, "Duplicate entry 'F67' for key 'uq_series_documentales_scope'", false],
];

$passed = 0;
foreach ($cases as [$name, $sqlState, $nativeCode, $message, $expected]) {
    $actual = GlobalPrefixDuplicateClassifier::isExpected(
        syntheticPdoException($sqlState, $nativeCode, $message)
    );
    if ($actual !== $expected) {
        throw new RuntimeException('Classifier case failed: ' . $name);
    }
    $passed++;
}

echo json_encode(['status' => 'PASS', 'tests' => count($cases), 'passed' => $passed], JSON_THROW_ON_ERROR) . PHP_EOL;

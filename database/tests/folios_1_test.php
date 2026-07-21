<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;

return new class implements DatabaseTest {
    private const COMPANY_A = 'qa-folios-empresa';
    private const COMPANY_B = 'qa-folios-empresa-b';
    private const WAREHOUSE_BO = 'qa-folios-bo';
    private const WAREHOUSE_MTY = 'qa-folios-mty';
    private const WAREHOUSE_BO_B = 'qa-folios-bo-b';
    private const FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match FOLIOS-DB-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $results['tables_exist'] = $this->tablesExist();
            $results['series_documentales_columns'] =
                $this->seriesColumnsValid();
            $results['documentos_folios_columns'] =
                $this->documentColumnsValid();
            $results['foreign_keys'] = $this->foreignKeysValid();
            $results['indexes'] = $this->indexesValid();
            $results['generated_columns'] = $this->generatedColumnsValid();
            $results['rollback'] = $this->rollbackCase();
            $results['document_numbering'] = $this->documentNumberingCases();
            $results['constraints'] = $this->constraintCases();
            $results['foreign_key_rejections'] = $this->foreignKeyCases();
            $results['no_functional_integration'] =
                $this->noFunctionalIntegration();

            foreach ($results as $label => $result) {
                if ($result !== true && !is_array($result)) {
                    throw new RuntimeException(
                        'FOLIOS-DB-1 failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('FOLIOS-DB-1 left QA data.');
        }

        return [
            'database' => $database,
            'tables' => [
                'series_documentales' =>
                    $this->tableExists('series_documentales'),
                'documentos_folios' =>
                    $this->tableExists('documentos_folios'),
            ],
            'cases' => $results,
            'folios_tested' => [
                'F-BO000001',
                'R-BO000001',
                'F-MTY000001',
                'TR-BO000001',
                'AJ-BO000001',
                'EN-BO000001',
                'SA-BO000001',
            ],
            'counts_before' => $before,
            'counts_during' => $during ?? [],
            'counts_after_cleanup' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    private function tablesExist(): bool
    {
        return $this->tableExists('series_documentales')
            && $this->tableExists('documentos_folios');
    }

    private function seriesColumnsValid(): bool
    {
        $expected = [
            'id',
            'empresa_id',
            'almacen_id',
            'tipo_documento',
            'codigo_serie',
            'prefijo',
            'codigo_almacen_snapshot',
            'formato',
            'separador',
            'siguiente_numero',
            'longitud',
            'reinicio_anual',
            'anio_actual',
            'activo',
            'creado_por',
            'actualizado_por',
            'eliminado_en',
            'eliminado_por',
            'creado_en',
            'actualizado_en',
        ];

        foreach ($expected as $column) {
            if (!$this->columnExists('series_documentales', $column)) {
                return false;
            }
        }

        return !$this->columnExists('series_documentales', 'almacen_scope_id');
    }

    private function documentColumnsValid(): bool
    {
        $expected = [
            'id',
            'serie_documental_id',
            'empresa_id',
            'almacen_id',
            'tipo_documento',
            'codigo_serie',
            'prefijo_documento',
            'codigo_almacen_snapshot',
            'formato',
            'folio',
            'numero',
            'anio',
            'anio_scope',
            'documento_tipo_origen',
            'documento_id_origen',
            'referencia_externa',
            'creado_por_usuario_id',
            'creado_en',
        ];

        foreach ($expected as $column) {
            if (!$this->columnExists('documentos_folios', $column)) {
                return false;
            }
        }

        return true;
    }

    private function foreignKeysValid(): bool
    {
        $expected = [
            ['series_documentales', 'fk_series_documentales_empresa', 'empresas'],
            ['series_documentales', 'fk_series_documentales_almacen', 'almacenes'],
            [
                'series_documentales',
                'fk_series_documentales_empresa_almacen',
                'almacenes',
            ],
            ['series_documentales', 'fk_series_documentales_creado_por', 'usuarios'],
            [
                'series_documentales',
                'fk_series_documentales_actualizado_por',
                'usuarios',
            ],
            [
                'series_documentales',
                'fk_series_documentales_eliminado_por',
                'usuarios',
            ],
            ['documentos_folios', 'fk_documentos_folios_serie', 'series_documentales'],
            ['documentos_folios', 'fk_documentos_folios_empresa', 'empresas'],
            ['documentos_folios', 'fk_documentos_folios_almacen', 'almacenes'],
            [
                'documentos_folios',
                'fk_documentos_folios_empresa_almacen',
                'almacenes',
            ],
            [
                'documentos_folios',
                'fk_documentos_folios_creado_por_usuario',
                'usuarios',
            ],
        ];

        foreach ($expected as [$table, $constraint, $referencedTable]) {
            if (!$this->foreignKeyExists($table, $constraint, $referencedTable)) {
                return false;
            }
        }

        return true;
    }

    private function indexesValid(): bool
    {
        $expected = [
            ['series_documentales', 'uq_series_documentales_scope'],
            ['series_documentales', 'idx_series_documentales_empresa'],
            ['series_documentales', 'idx_series_documentales_almacen'],
            ['series_documentales', 'idx_series_documentales_tipo_documento'],
            ['series_documentales', 'idx_series_documentales_codigo_serie'],
            ['series_documentales', 'idx_series_documentales_prefijo'],
            ['series_documentales', 'idx_series_documentales_almacen_snapshot'],
            ['series_documentales', 'idx_series_documentales_activo'],
            ['series_documentales', 'idx_series_documentales_eliminado_en'],
            ['documentos_folios', 'uq_documentos_folios_serie_numero'],
            ['documentos_folios', 'uq_documentos_folios_scope_folio'],
            ['documentos_folios', 'idx_documentos_folios_empresa'],
            ['documentos_folios', 'idx_documentos_folios_almacen'],
            ['documentos_folios', 'idx_documentos_folios_tipo_documento'],
            ['documentos_folios', 'idx_documentos_folios_codigo_serie'],
            ['documentos_folios', 'idx_documentos_folios_prefijo_documento'],
            ['documentos_folios', 'idx_documentos_folios_almacen_snapshot'],
            ['documentos_folios', 'idx_documentos_folios_folio'],
            ['documentos_folios', 'idx_documentos_folios_documento_origen'],
            ['documentos_folios', 'idx_documentos_folios_creado_en'],
        ];

        foreach ($expected as [$table, $index]) {
            if (!$this->indexExists($table, $index)) {
                return false;
            }
        }

        return true;
    }

    private function generatedColumnsValid(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT
                extra AS column_extra,
                generation_expression AS column_generation_expression
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => 'documentos_folios',
            'column_name' => 'anio_scope',
        ]);
        $column = $statement->fetch();

        return is_array($column)
            && str_contains(
                strtolower((string) $column['column_extra']),
                'stored'
            )
            && str_contains(
                strtolower((string) $column['column_generation_expression']),
                'coalesce'
            );
    }

    /**
     * @return array<string, bool>
     */
    private function rollbackCase(): array
    {
        $migration = require BASE_PATH
            . '/database/migrations/folios_1_001_create_document_numbering_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('FOLIOS migration contract is invalid.');
        }

        $migration->down($this->pdo);
        $removed = !$this->tableExists('documentos_folios')
            && !$this->tableExists('series_documentales');

        $migration->up($this->pdo);
        $recreated = $this->tableExists('series_documentales')
            && $this->tableExists('documentos_folios');

        return [
            'rollback_removes_documentos_folios' => $removed,
            'rollback_recreates_tables' => $recreated,
        ];
    }

    private function documentNumberingCases(): bool
    {
        $companyA = $this->createCompany(self::COMPANY_A, 'QA Folios Empresa');
        $companyB = $this->createCompany(self::COMPANY_B, 'QA Folios Empresa B');
        $warehouseBo = $this->createWarehouse(
            $companyA,
            self::WAREHOUSE_BO,
            'QA Almacén BO'
        );
        $warehouseMty = $this->createWarehouse(
            $companyA,
            self::WAREHOUSE_MTY,
            'QA Almacén MTY'
        );
        $warehouseBoB = $this->createWarehouse(
            $companyB,
            self::WAREHOUSE_BO_B,
            'QA Almacén BO B'
        );

        $seriesFBo = $this->createSeries(
            $companyA,
            $warehouseBo,
            'FACTURA_VENTA',
            'A',
            'F',
            'BO'
        );
        $seriesRBo = $this->createSeries(
            $companyA,
            $warehouseBo,
            'REMISION_VENTA',
            'A',
            'R',
            'BO'
        );
        $seriesFMty = $this->createSeries(
            $companyA,
            $warehouseMty,
            'FACTURA_VENTA',
            'A',
            'F',
            'MTY'
        );
        $seriesFBCompany = $this->createSeries(
            $companyB,
            $warehouseBoB,
            'FACTURA_VENTA',
            'A',
            'F',
            'BO'
        );

        $this->createDocument(
            $seriesFBo,
            $companyA,
            $warehouseBo,
            'FACTURA_VENTA',
            'A',
            'F',
            'BO',
            'F-BO000001',
            1
        );
        $this->createDocument(
            $seriesRBo,
            $companyA,
            $warehouseBo,
            'REMISION_VENTA',
            'A',
            'R',
            'BO',
            'R-BO000001',
            1
        );
        $this->createDocument(
            $seriesFMty,
            $companyA,
            $warehouseMty,
            'FACTURA_VENTA',
            'A',
            'F',
            'MTY',
            'F-MTY000001',
            1
        );
        $this->createDocument(
            $this->createSeries(
                $companyA,
                $warehouseBo,
                'TRANSFERENCIA_INVENTARIO',
                'A',
                'TR',
                'BO'
            ),
            $companyA,
            $warehouseBo,
            'TRANSFERENCIA_INVENTARIO',
            'A',
            'TR',
            'BO',
            'TR-BO000001',
            1
        );
        $this->createDocument(
            $this->createSeries(
                $companyA,
                $warehouseBo,
                'AJUSTE_INVENTARIO',
                'A',
                'AJ',
                'BO'
            ),
            $companyA,
            $warehouseBo,
            'AJUSTE_INVENTARIO',
            'A',
            'AJ',
            'BO',
            'AJ-BO000001',
            1
        );
        $this->createDocument(
            $this->createSeries(
                $companyA,
                $warehouseBo,
                'ENTRADA_INVENTARIO',
                'A',
                'EN',
                'BO'
            ),
            $companyA,
            $warehouseBo,
            'ENTRADA_INVENTARIO',
            'A',
            'EN',
            'BO',
            'EN-BO000001',
            1
        );
        $this->createDocument(
            $this->createSeries(
                $companyA,
                $warehouseBo,
                'SALIDA_INVENTARIO',
                'A',
                'SA',
                'BO'
            ),
            $companyA,
            $warehouseBo,
            'SALIDA_INVENTARIO',
            'A',
            'SA',
            'BO',
            'SA-BO000001',
            1
        );
        $this->createDocument(
            $seriesFBCompany,
            $companyB,
            $warehouseBoB,
            'FACTURA_VENTA',
            'A',
            'F',
            'BO',
            'F-BO000001',
            1
        );

        $this->createDocument(
            $seriesFBo,
            $companyA,
            $warehouseBo,
            'FACTURA_VENTA',
            'A',
            'F',
            'BO',
            'F-BO2026000001',
            1,
            2026
        );

        return $this->documentExists('F-BO000001', 'F', 'BO', self::FORMAT)
            && $this->documentExists('R-BO000001', 'R', 'BO', self::FORMAT)
            && $this->documentExists('F-MTY000001', 'F', 'MTY', self::FORMAT)
            && $this->fails(fn () => $this->createSeries(
                $companyA,
                $warehouseBo,
                'FACTURA_VENTA',
                'A',
                'F',
                'BO'
            ))
            && $this->fails(fn () => $this->createSeries(
                $companyA,
                0,
                'FACTURA_VENTA',
                'B',
                'F',
                'BO'
            ))
            && $this->fails(fn () => $this->createDocument(
                $seriesFBo,
                $companyA,
                $warehouseBo,
                'FACTURA_VENTA',
                'A',
                'F',
                'BO',
                'F-BO000001-DUP',
                1
            ))
            && $this->fails(fn () => $this->createDocument(
                $seriesFBo,
                $companyA,
                $warehouseBo,
                'FACTURA_VENTA',
                'A',
                'F',
                'BO',
                'F-BO000001',
                2
            ))
            && $this->anioScopeFor('F-BO000001') === 0
            && $this->anioScopeFor('F-BO2026000001') === 2026;
    }

    private function constraintCases(): bool
    {
        $company = $this->companyId(self::COMPANY_A);
        $warehouse = $this->warehouseId(self::WAREHOUSE_BO);
        $series = $this->createSeries(
            $company,
            $warehouse,
            'QA_CONSTRAINT',
            'A',
            'Q',
            'BO'
        );

        return $this->fails(fn () => $this->insertSeries([
            'empresa_id' => $company,
            'almacen_id' => $warehouse,
            'tipo_documento' => '',
            'codigo_serie' => 'B',
            'prefijo' => 'Q',
            'codigo_almacen_snapshot' => 'BO',
        ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_EMPTY_SERIE',
                'codigo_serie' => '',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_EMPTY_PREFIX',
                'codigo_serie' => 'A',
                'prefijo' => '',
                'codigo_almacen_snapshot' => 'BO',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_EMPTY_ALMACEN',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => '',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_EMPTY_FORMAT',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'formato' => '',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_BAD_NEXT',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'siguiente_numero' => 0,
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_BAD_LENGTH',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'longitud' => 13,
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_BAD_RESTART',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'reinicio_anual' => 2,
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_BAD_ACTIVE',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'activo' => 2,
            ]))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                '',
                'A',
                'Q',
                'BO',
                'Q-BO000001',
                1
            ))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                'QA_DOC_EMPTY_SERIE',
                '',
                'Q',
                'BO',
                'Q-BO000002',
                2
            ))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                'QA_DOC_EMPTY_PREFIX',
                'A',
                '',
                'BO',
                'Q-BO000003',
                3
            ))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                'QA_DOC_EMPTY_ALMACEN',
                'A',
                'Q',
                '',
                'Q-BO000004',
                4
            ))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                'QA_DOC_EMPTY_FOLIO',
                'A',
                'Q',
                'BO',
                '',
                5
            ))
            && $this->fails(fn () => $this->createDocument(
                $series,
                $company,
                $warehouse,
                'QA_DOC_BAD_NUMBER',
                'A',
                'Q',
                'BO',
                'Q-BO000000',
                0
            ))
            && $this->fails(fn () => $this->insertDocument([
                'serie_documental_id' => $series,
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_DOC_EMPTY_FORMAT',
                'codigo_serie' => 'A',
                'prefijo_documento' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'formato' => '',
                'folio' => 'Q-BO000006',
                'numero' => 6,
            ]));
    }

    private function foreignKeyCases(): bool
    {
        $company = $this->companyId(self::COMPANY_A);
        $warehouse = $this->warehouseId(self::WAREHOUSE_BO);
        $otherWarehouse = $this->warehouseId(self::WAREHOUSE_BO_B);
        $series = $this->createSeries(
            $company,
            $warehouse,
            'QA_FK_VALID',
            'A',
            'QF',
            'BO'
        );

        return $this->fails(fn () => $this->insertSeries([
            'empresa_id' => 99999999,
            'almacen_id' => $warehouse,
            'tipo_documento' => 'QA_BAD_COMPANY',
            'codigo_serie' => 'A',
            'prefijo' => 'Q',
            'codigo_almacen_snapshot' => 'BO',
        ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => 99999999,
                'tipo_documento' => 'QA_BAD_WAREHOUSE',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $otherWarehouse,
                'tipo_documento' => 'QA_WRONG_PAIR',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
            ]))
            && $this->fails(fn () => $this->insertSeries([
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_BAD_USER',
                'codigo_serie' => 'A',
                'prefijo' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'creado_por' => 99999999,
            ]))
            && $this->fails(fn () => $this->insertDocument([
                'serie_documental_id' => 99999999,
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_DOC_BAD_SERIE',
                'codigo_serie' => 'A',
                'prefijo_documento' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'formato' => self::FORMAT,
                'folio' => 'Q-BO009999',
                'numero' => 9999,
            ]))
            && $this->fails(fn () => $this->insertDocument([
                'serie_documental_id' => $series,
                'empresa_id' => $company,
                'almacen_id' => $warehouse,
                'tipo_documento' => 'QA_DOC_BAD_USER',
                'codigo_serie' => 'A',
                'prefijo_documento' => 'Q',
                'codigo_almacen_snapshot' => 'BO',
                'formato' => self::FORMAT,
                'folio' => 'Q-BO010000',
                'numero' => 10000,
                'creado_por_usuario_id' => 99999999,
            ]));
    }

    private function noFunctionalIntegration(): bool
    {
        return $this->columnExists('movimientos_inventario', 'referencia')
            && !$this->columnExists('movimientos_inventario', 'folio_id')
            && !$this->columnExists('movimientos_inventario_detalle', 'folio_id')
            && !$this->columnExists('transferencias_inventario', 'folio_id');
    }

    private function createCompany(string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre)
             VALUES (:codigo, :nombre)'
        );
        $statement->execute(['codigo' => $code, 'nombre' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, tipo_almacen)
             VALUES (:empresa_id, :codigo, :nombre, :tipo_almacen)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'tipo_almacen' => 'GENERAL',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSeries(
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode,
        string $prefix,
        string $warehouseSnapshot
    ): int {
        return $this->insertSeries([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
            'prefijo' => $prefix,
            'codigo_almacen_snapshot' => $warehouseSnapshot,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertSeries(array $data): int
    {
        $data = array_replace([
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 1,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'creado_por' => null,
            'actualizado_por' => null,
            'eliminado_por' => null,
        ], $data);

        $statement = $this->pdo->prepare(
            'INSERT INTO series_documentales (
                empresa_id,
                almacen_id,
                tipo_documento,
                codigo_serie,
                prefijo,
                codigo_almacen_snapshot,
                formato,
                separador,
                siguiente_numero,
                longitud,
                reinicio_anual,
                anio_actual,
                activo,
                creado_por,
                actualizado_por,
                eliminado_por
            ) VALUES (
                :empresa_id,
                :almacen_id,
                :tipo_documento,
                :codigo_serie,
                :prefijo,
                :codigo_almacen_snapshot,
                :formato,
                :separador,
                :siguiente_numero,
                :longitud,
                :reinicio_anual,
                :anio_actual,
                :activo,
                :creado_por,
                :actualizado_por,
                :eliminado_por
            )'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    private function createDocument(
        int $seriesId,
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode,
        string $prefix,
        string $warehouseSnapshot,
        string $folio,
        int $number,
        ?int $year = null
    ): int {
        return $this->insertDocument([
            'serie_documental_id' => $seriesId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
            'prefijo_documento' => $prefix,
            'codigo_almacen_snapshot' => $warehouseSnapshot,
            'formato' => self::FORMAT,
            'folio' => $folio,
            'numero' => $number,
            'anio' => $year,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertDocument(array $data): int
    {
        $data = array_replace([
            'anio' => null,
            'documento_tipo_origen' => null,
            'documento_id_origen' => null,
            'referencia_externa' => null,
            'creado_por_usuario_id' => null,
        ], $data);

        $statement = $this->pdo->prepare(
            'INSERT INTO documentos_folios (
                serie_documental_id,
                empresa_id,
                almacen_id,
                tipo_documento,
                codigo_serie,
                prefijo_documento,
                codigo_almacen_snapshot,
                formato,
                folio,
                numero,
                anio,
                documento_tipo_origen,
                documento_id_origen,
                referencia_externa,
                creado_por_usuario_id
            ) VALUES (
                :serie_documental_id,
                :empresa_id,
                :almacen_id,
                :tipo_documento,
                :codigo_serie,
                :prefijo_documento,
                :codigo_almacen_snapshot,
                :formato,
                :folio,
                :numero,
                :anio,
                :documento_tipo_origen,
                :documento_id_origen,
                :referencia_externa,
                :creado_por_usuario_id
            )'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    private function documentExists(
        string $folio,
        string $prefix,
        string $warehouseSnapshot,
        string $format
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM documentos_folios
             WHERE folio = :folio
               AND prefijo_documento = :prefijo_documento
               AND codigo_almacen_snapshot = :codigo_almacen_snapshot
               AND formato = :formato'
        );
        $statement->execute([
            'folio' => $folio,
            'prefijo_documento' => $prefix,
            'codigo_almacen_snapshot' => $warehouseSnapshot,
            'formato' => $format,
        ]);

        return (int) $statement->fetchColumn() >= 1;
    }

    private function anioScopeFor(string $folio): int
    {
        $statement = $this->pdo->prepare(
            'SELECT anio_scope
             FROM documentos_folios
             WHERE folio = :folio
             LIMIT 1'
        );
        $statement->execute(['folio' => $folio]);

        return (int) $statement->fetchColumn();
    }

    private function companyId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM empresas WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('QA company was not found.');
        }

        return $id;
    }

    private function warehouseId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM almacenes WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('QA warehouse was not found.');
        }

        return $id;
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name'
        );
        $statement->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() >= 1;
    }

    private function foreignKeyExists(
        string $table,
        string $constraint,
        string $referencedTable
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND constraint_name = :constraint_name
               AND referenced_table_name = :referenced_table_name'
        );
        $statement->execute([
            'table_name' => $table,
            'constraint_name' => $constraint,
            'referenced_table_name' => $referencedTable,
        ]);

        return (int) $statement->fetchColumn() >= 1;
    }

    /**
     * @return array<string, int>
     */
    private function qaCounts(): array
    {
        return [
            'series_documentales_qa' => $this->tableExists('series_documentales')
                ? (int) $this->pdo->query(
                    "SELECT COUNT(*)
                     FROM series_documentales
                     WHERE tipo_documento LIKE 'QA_%'
                        OR codigo_almacen_snapshot IN ('BO', 'MTY')"
                )->fetchColumn()
                : 0,
            'documentos_folios_qa' => $this->tableExists('documentos_folios')
                ? (int) $this->pdo->query(
                    "SELECT COUNT(*)
                     FROM documentos_folios
                     WHERE tipo_documento LIKE 'QA_%'
                        OR folio IN (
                            'F-BO000001',
                            'R-BO000001',
                            'F-MTY000001',
                            'TR-BO000001',
                            'AJ-BO000001',
                            'EN-BO000001',
                            'SA-BO000001',
                            'F-BO2026000001'
                        )"
                )->fetchColumn()
                : 0,
            'empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM empresas WHERE codigo LIKE 'qa-folios-%'"
            )->fetchColumn(),
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM almacenes WHERE codigo LIKE 'qa-folios-%'"
            )->fetchColumn(),
            'usuarios_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        if ($this->tableExists('documentos_folios')) {
            $this->pdo->exec(
                "DELETE FROM documentos_folios
                 WHERE tipo_documento LIKE 'QA_%'
                    OR folio IN (
                        'F-BO000001',
                        'R-BO000001',
                        'F-MTY000001',
                        'TR-BO000001',
                        'AJ-BO000001',
                        'EN-BO000001',
                        'SA-BO000001',
                        'F-BO2026000001'
                    )"
            );
        }

        if ($this->tableExists('series_documentales')) {
            $this->pdo->exec(
                "DELETE FROM series_documentales
                 WHERE tipo_documento LIKE 'QA_%'
                    OR codigo_almacen_snapshot IN ('BO', 'MTY')"
            );
        }

        $this->pdo->exec(
            "DELETE FROM almacenes WHERE codigo LIKE 'qa-folios-%'"
        );
        $this->pdo->exec(
            "DELETE FROM empresas WHERE codigo LIKE 'qa-folios-%'"
        );
    }
};

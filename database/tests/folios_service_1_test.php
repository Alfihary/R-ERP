<?php

declare(strict_types=1);

use App\Domain\Folios\FolioService;
use App\Domain\Folios\FolioValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\FolioRepository;

return new class implements DatabaseTest {
    private const COMPANY_A = 'qa-folios-service-a';
    private const COMPANY_B = 'qa-folios-service-b';
    private const WAREHOUSE_BO = 'qa-folios-service-bo';
    private const WAREHOUSE_MTY = 'qa-folios-service-mty';
    private const WAREHOUSE_BO_B = 'qa-folios-service-bo-b';
    private const FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    private PDO $pdo;
    private int $adminId;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match FOLIOS-SERVICE-1.'
            );
        }

        if (!$this->tableExists('series_documentales')
            || !$this->tableExists('documentos_folios')
        ) {
            throw new RuntimeException('FOLIOS-SERVICE-1 requires FOLIOS-DB-1.');
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $this->adminId = $this->adminId();
            $context = $this->createQaContext();
            $results['emission'] = $this->emissionCases($context);
            $results['validation'] = $this->validationCases($context);
            $results['rollback'] = $this->rollbackCase($context);
            $results['annual_restart'] = $this->annualRestartCases($context);
            $results['db_uniqueness'] = $this->dbUniquenessCases($context);
            $results['payload_columns'] = $this->payloadColumnsCase();

            foreach ($results as $label => $result) {
                if ($result !== true && !is_array($result)) {
                    throw new RuntimeException(
                        'FOLIOS-SERVICE-1 failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('FOLIOS-SERVICE-1 left QA data.');
        }

        return [
            'database' => $database,
            'cases' => $results,
            'folios_emitted' => [
                'F-BO000001',
                'F-BO000002',
                'R-BO000001',
                'F-MTY000001',
            ],
            'counts_before' => $before,
            'counts_during' => $during ?? [],
            'counts_after_cleanup' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    /**
     * @param array<string, int> $context
     * @return array<string, mixed>
     */
    private function emissionCases(array $context): array
    {
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'FACTURA_VENTA',
            'F',
            'F',
            'BO'
        );
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'REMISION_VENTA',
            'R',
            'R',
            'BO'
        );
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_mty'],
            'FACTURA_VENTA',
            'F',
            'F',
            'MTY'
        );
        $this->createSeries(
            $context['company_b'],
            $context['warehouse_bo_b'],
            'FACTURA_VENTA',
            'F',
            'F',
            'BO'
        );

        $first = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'FACTURA_VENTA',
            'F'
        ));
        $second = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'FACTURA_VENTA',
            'F'
        ));
        $remision = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'REMISION_VENTA',
            'R'
        ));
        $mty = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_mty'],
            'FACTURA_VENTA',
            'F'
        ));
        $otherCompany = $this->service()->emitir($this->input(
            $context['company_b'],
            $context['warehouse_bo_b'],
            'FACTURA_VENTA',
            'F'
        ));

        return [
            'first_invoice' => $first['folio'] === 'F-BO000001',
            'second_invoice' => $second['folio'] === 'F-BO000002',
            'remision_independent' => $remision['folio'] === 'R-BO000001',
            'warehouse_independent' => $mty['folio'] === 'F-MTY000001',
            'company_independent' => $otherCompany['folio'] === 'F-BO000001',
            'snapshot_used' =>
                $this->changeWarehouseCode($context['warehouse_bo'])
                && $this->service()->emitir($this->input(
                    $context['company_a'],
                    $context['warehouse_bo'],
                    'FACTURA_VENTA',
                    'F'
                ))['folio'] === 'F-BO000003',
            'next_number' => $this->seriesNextNumber(
                $context['company_a'],
                $context['warehouse_bo'],
                'FACTURA_VENTA',
                'F'
            ) === 4,
        ];
    }

    /**
     * @param array<string, int> $context
     * @return array<string, bool>
     */
    private function validationCases(array $context): array
    {
        $inactive = $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_INACTIVA',
            'A',
            'QI',
            'BO',
            ['activo' => 0]
        );
        $deleted = $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ELIMINADA',
            'A',
            'QE',
            'BO',
            ['eliminado_en' => date('Y-m-d H:i:s')]
        );
        $badFormat = $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_FORMATO',
            'A',
            'QF',
            'BO',
            ['formato' => '{PREFIJO}/{NUMERO}']
        );

        return [
            'series_ids_created' => $inactive > 0 && $deleted > 0 && $badFormat > 0,
            'missing_series' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], 'QA_NO_EXISTE', 'A')
            )),
            'inactive_series' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], 'QA_INACTIVA', 'A')
            )),
            'deleted_series' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], 'QA_ELIMINADA', 'A')
            )),
            'wrong_company_warehouse' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo_b'], 'FACTURA_VENTA', 'F')
            )),
            'invalid_creator' => $this->fails(fn () => $this->service()->emitir(
                $this->input(
                    $context['company_a'],
                    $context['warehouse_bo'],
                    'FACTURA_VENTA',
                    'F',
                    ['creado_por_usuario_id' => 99999999]
                )
            )),
            'unsupported_format' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], 'QA_FORMATO', 'A')
            )),
            'empty_type' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], '', 'F')
            )),
            'empty_series_code' => $this->fails(fn () => $this->service()->emitir(
                $this->input($context['company_a'], $context['warehouse_bo'], 'FACTURA_VENTA', '')
            )),
        ];
    }

    /**
     * @param array<string, int> $context
     * @return array<string, bool>
     */
    private function rollbackCase(array $context): array
    {
        $seriesId = $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ROLLBACK',
            'A',
            'QR',
            'BO'
        );
        $failed = $this->fails(fn () => $this->service()->emitir(
            $this->input(
                $context['company_a'],
                $context['warehouse_bo'],
                'QA_ROLLBACK',
                'A',
                ['__simulate_failure_after_lock' => true]
            )
        ));

        return [
            'controlled_failure' => $failed,
            'next_number_not_incremented' => $this->seriesNextNumberById($seriesId) === 1,
            'no_orphan_document' => $this->documentCountForSeries($seriesId) === 0,
        ];
    }

    /**
     * @param array<string, int> $context
     * @return array<string, bool>
     */
    private function annualRestartCases(array $context): array
    {
        $currentYear = (int) date('Y');
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ANUAL_NULL',
            'A',
            'QA',
            'BO',
            ['reinicio_anual' => 1, 'anio_actual' => null, 'siguiente_numero' => 8]
        );
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ANUAL_PREVIO',
            'A',
            'QP',
            'BO',
            ['reinicio_anual' => 1, 'anio_actual' => $currentYear - 1, 'siguiente_numero' => 12]
        );

        $first = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ANUAL_NULL',
            'A'
        ));
        $second = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ANUAL_NULL',
            'A'
        ));
        $previous = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_ANUAL_PREVIO',
            'A'
        ));

        return [
            'null_year_starts_at_one' =>
                (int) $first['numero'] === 1
                && (int) $first['anio'] === $currentYear,
            'same_year_continues' =>
                (int) $second['numero'] === 2
                && (int) $second['anio'] === $currentYear,
            'previous_year_restarts' =>
                (int) $previous['numero'] === 1
                && (int) $previous['anio'] === $currentYear,
            'anio_actual_updated' => $this->seriesYear(
                $context['company_a'],
                $context['warehouse_bo'],
                'QA_ANUAL_PREVIO',
                'A'
            ) === $currentYear,
        ];
    }

    /**
     * @param array<string, int> $context
     * @return array<string, bool>
     */
    private function dbUniquenessCases(array $context): array
    {
        $this->createSeries(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_DUP_FOLIO',
            'A',
            'QD',
            'BO'
        );
        $first = $this->service()->emitir($this->input(
            $context['company_a'],
            $context['warehouse_bo'],
            'QA_DUP_FOLIO',
            'A'
        ));
        $this->forceNextNumber((int) $first['serie_documental_id'], 1);

        $duplicateNumberAndFolio = $this->fails(fn () => $this->service()->emitir(
            $this->input($context['company_a'], $context['warehouse_bo'], 'QA_DUP_FOLIO', 'A')
        ));

        return [
            'duplicate_folio_controlled' => $duplicateNumberAndFolio,
            'next_number_rolled_back' =>
                $this->seriesNextNumberById((int) $first['serie_documental_id']) === 1,
        ];
    }

    private function payloadColumnsCase(): bool
    {
        $statement = $this->pdo->query(
            "SELECT
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
                creado_por_usuario_id,
                creado_en
             FROM documentos_folios
             WHERE folio = 'F-BO000001'
             LIMIT 1"
        );
        $row = $statement->fetch();

        return is_array($row)
            && (int) $row['serie_documental_id'] > 0
            && (int) $row['empresa_id'] > 0
            && (int) $row['almacen_id'] > 0
            && $row['tipo_documento'] === 'FACTURA_VENTA'
            && $row['codigo_serie'] === 'F'
            && $row['prefijo_documento'] === 'F'
            && $row['codigo_almacen_snapshot'] === 'BO'
            && $row['formato'] === self::FORMAT
            && $row['folio'] === 'F-BO000001'
            && (int) $row['numero'] === 1
            && $row['anio'] === null
            && $row['documento_tipo_origen'] === null
            && $row['documento_id_origen'] === null
            && $row['referencia_externa'] === null
            && (int) $row['creado_por_usuario_id'] === $this->adminId
            && $row['creado_en'] !== null;
    }

    /**
     * @return array<string, int>
     */
    private function createQaContext(): array
    {
        $companyA = $this->createCompany(self::COMPANY_A, 'QA Folios Servicio A');
        $companyB = $this->createCompany(self::COMPANY_B, 'QA Folios Servicio B');
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

        return [
            'company_a' => $companyA,
            'company_b' => $companyB,
            'warehouse_bo' => $warehouseBo,
            'warehouse_mty' => $warehouseMty,
            'warehouse_bo_b' => $warehouseBoB,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode,
        array $overrides = []
    ): array {
        return array_replace([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
            'documento_tipo_origen' => null,
            'documento_id_origen' => null,
            'referencia_externa' => null,
            'creado_por_usuario_id' => $this->adminId,
        ], $overrides);
    }

    private function service(): FolioService
    {
        return new FolioService(
            new FolioRepository(new ConnectionProvider($this->databaseConfig()))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfig(): array
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database config is invalid.');
        }

        return $databaseConfig;
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (FolioValidationException) {
            return true;
        }

        return false;
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSeries(
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode,
        string $prefix,
        string $warehouseSnapshot,
        array $overrides = []
    ): int {
        $data = array_replace([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
            'prefijo' => $prefix,
            'codigo_almacen_snapshot' => $warehouseSnapshot,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 1,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'eliminado_en' => null,
        ], $overrides);

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
                eliminado_en
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
                :eliminado_en
            )'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    private function changeWarehouseCode(int $warehouseId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE almacenes
             SET codigo = :codigo
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $warehouseId,
            'codigo' => 'qa-folios-service-bo-edit',
        ]);

        return true;
    }

    private function forceNextNumber(int $seriesId, int $nextNumber): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE series_documentales
             SET siguiente_numero = :siguiente_numero
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $seriesId,
            'siguiente_numero' => $nextNumber,
        ]);
    }

    private function seriesNextNumber(
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT siguiente_numero
             FROM series_documentales
             WHERE empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND tipo_documento = :tipo_documento
               AND codigo_serie = :codigo_serie
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function seriesNextNumberById(int $seriesId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT siguiente_numero
             FROM series_documentales
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $seriesId]);

        return (int) $statement->fetchColumn();
    }

    private function seriesYear(
        int $companyId,
        int $warehouseId,
        string $documentType,
        string $seriesCode
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT anio_actual
             FROM series_documentales
             WHERE empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND tipo_documento = :tipo_documento
               AND codigo_serie = :codigo_serie
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $documentType,
            'codigo_serie' => $seriesCode,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function documentCountForSeries(int $seriesId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM documentos_folios
             WHERE serie_documental_id = :serie_documental_id'
        );
        $statement->execute(['serie_documental_id' => $seriesId]);

        return (int) $statement->fetchColumn();
    }

    private function adminId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT u.id
             FROM usuarios u
             INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.codigo = 'ADMIN'
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE u.activo = 1
               AND u.eliminado_en IS NULL
             ORDER BY u.id
             LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Active ADMIN user is required.');
        }

        return $id;
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

    /**
     * @return array<string, int>
     */
    private function qaCounts(): array
    {
        return [
            'series_documentales_qa' => $this->tableExists('series_documentales')
                ? (int) $this->pdo->query(
                    "SELECT COUNT(*)
                     FROM series_documentales sd
                     INNER JOIN empresas e ON e.id = sd.empresa_id
                     WHERE e.codigo LIKE 'qa-folios-service-%'"
                )->fetchColumn()
                : 0,
            'documentos_folios_qa' => $this->tableExists('documentos_folios')
                ? (int) $this->pdo->query(
                    "SELECT COUNT(*)
                     FROM documentos_folios df
                     INNER JOIN empresas e ON e.id = df.empresa_id
                     WHERE e.codigo LIKE 'qa-folios-service-%'"
                )->fetchColumn()
                : 0,
            'empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM empresas WHERE codigo LIKE 'qa-folios-service-%'"
            )->fetchColumn(),
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM almacenes WHERE codigo LIKE 'qa-folios-service-%'"
            )->fetchColumn(),
            'usuarios_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        if ($this->tableExists('documentos_folios')) {
            $this->pdo->exec(
                "DELETE df
                 FROM documentos_folios df
                 INNER JOIN empresas e ON e.id = df.empresa_id
                 WHERE e.codigo LIKE 'qa-folios-service-%'"
            );
        }

        if ($this->tableExists('series_documentales')) {
            $this->pdo->exec(
                "DELETE sd
                 FROM series_documentales sd
                 INNER JOIN empresas e ON e.id = sd.empresa_id
                 WHERE e.codigo LIKE 'qa-folios-service-%'"
            );
        }

        $this->pdo->exec(
            "DELETE FROM almacenes WHERE codigo LIKE 'qa-folios-service-%'"
        );
        $this->pdo->exec(
            "DELETE FROM empresas WHERE codigo LIKE 'qa-folios-service-%'"
        );
    }
};

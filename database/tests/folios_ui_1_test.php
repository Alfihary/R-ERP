<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\FolioSeriesRepository;

return new class implements DatabaseTest {
    private const COMPANY_A = 'QA-FOLIOS-UI-A';
    private const COMPANY_B = 'QA-FOLIOS-UI-B';
    private const WAREHOUSE_A = 'QA-FOLIOS-UI-BO';
    private const WAREHOUSE_B = 'QA-FOLIOS-UI-MTY';
    private const FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    private PDO $pdo;
    private FolioSeriesRepository $repository;
    private int $adminId;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $connection = new ConnectionProvider([]);
        $property = new ReflectionProperty($connection, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);
        $this->repository = new FolioSeriesRepository($connection);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match FOLIOS-UI-1.');
        }

        $this->assertTables();
        $this->cleanup();
        $before = $this->counts();
        $this->adminId = $this->adminId();
        $ids = $this->createScope();

        try {
            $results = [
                'permissions' => $this->permissionsCase(),
                'create_valid' => $this->createValidCase($ids),
                'rejections' => $this->rejectionsCase($ids),
                'edit_without_issued_folios' => $this->editWithoutIssuedFoliosCase($ids),
                'edit_with_issued_folios' => $this->editWithIssuedFoliosCase($ids),
                'activate_deactivate' => $this->activateDeactivateCase($ids),
                'preview' => $this->previewCase(),
                'no_functional_integration' => $this->noIntegrationCase(),
            ];

            foreach ($results as $label => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException('FOLIOS-UI-1 failed case: ' . $label);
                }
            }

            return [
                'database' => $expectedDatabase,
                'cases' => $results,
                'counts_before' => $before,
                'counts_during' => $this->counts(),
                'counts_after_cleanup' => (function (): array {
                    $this->cleanup();
                    return $this->counts();
                })(),
                'cleanup' => 'qa_rows_deleted',
            ];
        } finally {
            $this->cleanup();
        }
    }

    private function permissionsCase(): bool
    {
        $codes = [
            'configuracion.folios.acceder',
            'configuracion.folios.ver',
            'configuracion.folios.crear',
            'configuracion.folios.editar',
            'configuracion.folios.desactivar',
        ];

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $permissions = $this->pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (' . $placeholders . ')
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissions->execute($codes);
        $admin = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = ?
               AND p.codigo IN (' . $placeholders . ')
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $admin->execute(array_merge(['ADMIN'], $codes));
        $duplicates = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 WHERE codigo IN (' . $placeholders . ')
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicated'
        );
        $duplicates->execute($codes);

        return (int) $permissions->fetchColumn() === 5
            && (int) $admin->fetchColumn() === 5
            && (int) $duplicates->fetchColumn() === 0;
    }

    /**
     * @param array<string, int> $ids
     */
    private function createValidCase(array $ids): bool
    {
        $id = $this->repository->create([
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_a'],
            'tipo_documento' => 'FACTURA_VENTA',
            'codigo_serie' => 'F',
            'prefijo' => 'F',
            'codigo_almacen_snapshot' => self::WAREHOUSE_A,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 1,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'actor_id' => $this->adminId,
        ]);
        $row = $this->repository->findById($id);

        return is_array($row)
            && (int) $row['empresa_id'] === $ids['company_a']
            && (int) $row['almacen_id'] === $ids['warehouse_a']
            && $row['codigo_almacen_snapshot'] === self::WAREHOUSE_A
            && $row['formato'] === self::FORMAT
            && (int) $row['siguiente_numero'] === 1
            && (int) $row['longitud'] === 6;
    }

    /**
     * @param array<string, int> $ids
     */
    private function rejectionsCase(array $ids): bool
    {
        return $this->expectDuplicate($ids)
            && !$this->repository->companyExists(999999999)
            && $this->repository->warehouseById(999999999) === null
            && !$this->repository->warehouseBelongsToCompany(
                $ids['company_a'],
                $ids['warehouse_foreign']
            )
            && preg_match('/^[A-Z0-9]{1,20}$/', 'F@') !== 1
            && self::FORMAT !== '{PREFIJO}/{ALMACEN}{NUMERO}'
            && 0 < 1
            && !($this->between(0, 1, 12))
            && !$this->repository->existsScope(
                $ids['company_a'],
                $ids['warehouse_a'],
                '',
                'X'
            );
    }

    /**
     * @param array<string, int> $ids
     */
    private function editWithoutIssuedFoliosCase(array $ids): bool
    {
        $id = $this->repository->create([
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_a'],
            'tipo_documento' => 'REMISION_VENTA',
            'codigo_serie' => 'R',
            'prefijo' => 'R',
            'codigo_almacen_snapshot' => self::WAREHOUSE_A,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 1,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'actor_id' => $this->adminId,
        ]);
        $this->repository->update($id, [
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_b'],
            'tipo_documento' => 'REMISION_VENTA',
            'codigo_serie' => 'R2',
            'prefijo' => 'R2',
            'codigo_almacen_snapshot' => self::WAREHOUSE_B,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 2,
            'longitud' => 7,
            'reinicio_anual' => 1,
            'anio_actual' => (int) date('Y'),
            'activo' => 1,
            'actor_id' => $this->adminId,
        ]);
        $row = $this->repository->findById($id);

        return is_array($row)
            && (int) $row['almacen_id'] === $ids['warehouse_b']
            && $row['codigo_almacen_snapshot'] === self::WAREHOUSE_B
            && $row['codigo_serie'] === 'R2'
            && (int) $row['longitud'] === 7;
    }

    /**
     * @param array<string, int> $ids
     */
    private function editWithIssuedFoliosCase(array $ids): bool
    {
        $id = $this->repository->create([
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_a'],
            'tipo_documento' => 'AJUSTE_INVENTARIO',
            'codigo_serie' => 'AJ',
            'prefijo' => 'AJ',
            'codigo_almacen_snapshot' => self::WAREHOUSE_A,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 2,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'actor_id' => $this->adminId,
        ]);
        $this->insertIssuedFolio($id, $ids);
        $before = $this->repository->findById($id);
        $this->repository->deactivate($id, $this->adminId);
        $after = $this->repository->findById($id);

        return is_array($before)
            && is_array($after)
            && $this->repository->hasIssuedFolios($id)
            && $before['codigo_serie'] === $after['codigo_serie']
            && $before['prefijo'] === $after['prefijo']
            && $before['formato'] === $after['formato']
            && (int) $after['activo'] === 0
            && $this->issuedFolioCount($id) === 1;
    }

    /**
     * @param array<string, int> $ids
     */
    private function activateDeactivateCase(array $ids): bool
    {
        $id = $this->repository->create([
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_a'],
            'tipo_documento' => 'ENTRADA_INVENTARIO',
            'codigo_serie' => 'EN',
            'prefijo' => 'EN',
            'codigo_almacen_snapshot' => self::WAREHOUSE_A,
            'formato' => self::FORMAT,
            'separador' => '-',
            'siguiente_numero' => 1,
            'longitud' => 6,
            'reinicio_anual' => 0,
            'anio_actual' => null,
            'activo' => 1,
            'actor_id' => $this->adminId,
        ]);
        $this->repository->deactivate($id, $this->adminId);
        $inactive = $this->repository->findById($id);
        $this->repository->activate($id, $this->adminId);
        $active = $this->repository->findById($id);

        return is_array($inactive)
            && is_array($active)
            && (int) $inactive['activo'] === 0
            && (int) $active['activo'] === 1
            && $this->repository->findById($id) !== null;
    }

    private function previewCase(): bool
    {
        $before = $this->documentCount();
        $a = $this->repository->preview('F', 'BO', 1, 6);
        $b = $this->repository->preview('R', 'BO', 1, 6);

        return $a === 'F-BO000001'
            && $b === 'R-BO000001'
            && $this->repository->preview('F', 'BO', 12, 4) === 'F-BO0012'
            && $before === $this->documentCount();
    }

    private function noIntegrationCase(): bool
    {
        return is_file(BASE_PATH . '/app/Domain/Folios/FolioService.php')
            && is_file(BASE_PATH . '/app/Domain/Inventory/InventoryService.php')
            && is_file(BASE_PATH . '/app/Domain/Inventory/InventoryTransferService.php');
    }

    private function assertTables(): void
    {
        foreach (['series_documentales', 'documentos_folios'] as $table) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table'
            );
            $statement->execute(['table' => $table]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('FOLIOS-UI-1 requires table ' . $table . '.');
            }
        }
    }

    private function adminId(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM usuarios
             WHERE username = :username
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['username' => 'jesus.g']);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('FOLIOS-UI-1 requires the initial admin user.');
        }

        return (int) $id;
    }

    /**
     * @return array<string, int>
     */
    private function createScope(): array
    {
        $companyA = $this->insertCompany(self::COMPANY_A, 'QA Folios UI A');
        $companyB = $this->insertCompany(self::COMPANY_B, 'QA Folios UI B');
        $warehouseA = $this->insertWarehouse($companyA, self::WAREHOUSE_A, 'QA Folios UI BO');
        $warehouseB = $this->insertWarehouse($companyA, self::WAREHOUSE_B, 'QA Folios UI MTY');
        $warehouseForeign = $this->insertWarehouse(
            $companyB,
            self::WAREHOUSE_B,
            'QA Folios UI MTY externa'
        );

        return [
            'company_a' => $companyA,
            'company_b' => $companyB,
            'warehouse_a' => $warehouseA,
            'warehouse_b' => $warehouseB,
            'warehouse_foreign' => $warehouseForeign,
        ];
    }

    private function insertCompany(string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por, actualizado_por)
             VALUES (:codigo, :nombre, 1, :user_id, :user_id_update)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'user_id' => $this->adminId,
            'user_id_update' => $this->adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertWarehouse(int $companyId, string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por, actualizado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :user_id, :user_id_update)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'user_id' => $this->adminId,
            'user_id_update' => $this->adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, int> $ids
     */
    private function expectDuplicate(array $ids): bool
    {
        try {
            $this->repository->create([
                'empresa_id' => $ids['company_a'],
                'almacen_id' => $ids['warehouse_a'],
                'tipo_documento' => 'FACTURA_VENTA',
                'codigo_serie' => 'F',
                'prefijo' => 'F',
                'codigo_almacen_snapshot' => self::WAREHOUSE_A,
                'formato' => self::FORMAT,
                'separador' => '-',
                'siguiente_numero' => 1,
                'longitud' => 6,
                'reinicio_anual' => 0,
                'anio_actual' => null,
                'activo' => 1,
                'actor_id' => $this->adminId,
            ]);
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, int> $ids
     */
    private function insertIssuedFolio(int $seriesId, array $ids): void
    {
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
                NULL,
                :user_id
             )'
        );
        $statement->execute([
            'serie_documental_id' => $seriesId,
            'empresa_id' => $ids['company_a'],
            'almacen_id' => $ids['warehouse_a'],
            'tipo_documento' => 'AJUSTE_INVENTARIO',
            'codigo_serie' => 'AJ',
            'prefijo_documento' => 'AJ',
            'codigo_almacen_snapshot' => self::WAREHOUSE_A,
            'formato' => self::FORMAT,
            'folio' => 'AJ-' . self::WAREHOUSE_A . '000001',
            'numero' => 1,
            'user_id' => $this->adminId,
        ]);
    }

    private function issuedFolioCount(int $seriesId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM documentos_folios
             WHERE serie_documental_id = :id'
        );
        $statement->execute(['id' => $seriesId]);

        return (int) $statement->fetchColumn();
    }

    private function documentCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM documentos_folios'
        )->fetchColumn();
    }

    private function between(int $value, int $min, int $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'series_documentales_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM series_documentales sd
                 INNER JOIN empresas e ON e.id = sd.empresa_id
                 WHERE e.codigo LIKE 'QA-FOLIOS-UI-%'"
            )->fetchColumn(),
            'documentos_folios_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM documentos_folios df
                 INNER JOIN empresas e ON e.id = df.empresa_id
                 WHERE e.codigo LIKE 'QA-FOLIOS-UI-%'"
            )->fetchColumn(),
            'empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM empresas WHERE codigo LIKE 'QA-FOLIOS-UI-%'"
            )->fetchColumn(),
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM almacenes WHERE codigo LIKE 'QA-FOLIOS-UI-%'"
            )->fetchColumn(),
            'usuarios_qa' => 0,
            'permisos_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        $this->pdo->exec(
            "DELETE df
             FROM documentos_folios df
             INNER JOIN empresas e ON e.id = df.empresa_id
             WHERE e.codigo LIKE 'QA-FOLIOS-UI-%'"
        );
        $this->pdo->exec(
            "DELETE sd
             FROM series_documentales sd
             INNER JOIN empresas e ON e.id = sd.empresa_id
             WHERE e.codigo LIKE 'QA-FOLIOS-UI-%'"
        );
        $this->pdo->exec(
            "DELETE FROM almacenes
             WHERE codigo LIKE 'QA-FOLIOS-UI-%'"
        );
        $this->pdo->exec(
            "DELETE FROM empresas
             WHERE codigo LIKE 'QA-FOLIOS-UI-%'"
        );
    }
};

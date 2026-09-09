<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductRequestTicketRepository
{
    private const FOLIO_TYPE = 'TICKET_PRODUCTO';
    private const FOLIO_SERIES = 'TP';
    private const FOLIO_FORMAT = '{ALMACEN}-{NUMERO}';

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function beginTransaction(): bool
    {
        $pdo = $this->connection->pdo();

        if ($pdo->inTransaction()) {
            return false;
        }

        $pdo->beginTransaction();

        return true;
    }

    public function commit(bool $ownsTransaction = true): void
    {
        if ($ownsTransaction) {
            $this->connection->pdo()->commit();
        }
    }

    public function rollBack(bool $ownsTransaction = true): void
    {
        $pdo = $this->connection->pdo();

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, perPage: int, total: int, totalPages: int},
     *     filters: array<string, mixed>
     * }
     */
    public function listar(array $filters, int $page = 1, int $perPage = 20): array
    {
        $filters = $this->normalizeListFilters($filters);
        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 20, 50], true) ? $perPage : 20;
        $where = ['tp.deleted_at IS NULL'];
        $params = [];

        if ($filters['folio'] !== '') {
            $where[] = "tp.folio LIKE :folio ESCAPE '\\\\'";
            $params['folio'] = '%' . $this->escapeLike($filters['folio']) . '%';
        }

        if ($filters['estado'] !== '') {
            $where[] = 'tp.estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if ($filters['empresa_id'] !== null) {
            $where[] = 'tp.empresa_id = :empresa_id';
            $params['empresa_id'] = $filters['empresa_id'];
        }

        if ($filters['almacen_id'] !== null) {
            $where[] = 'tp.almacen_id = :almacen_id';
            $params['almacen_id'] = $filters['almacen_id'];
        }

        if ($filters['fecha_desde'] !== '') {
            $where[] = 'tp.created_at >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'] . ' 00:00:00';
        }

        if ($filters['fecha_hasta'] !== '') {
            $where[] = 'tp.created_at <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM tickets_productos tp
             WHERE ' . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $statement = $this->connection->pdo()->prepare(
            'SELECT
                tp.id,
                tp.folio,
                tp.estado,
                tp.empresa_id,
                e.nombre AS empresa_nombre,
                tp.almacen_id,
                a.nombre AS almacen_nombre,
                a.codigo AS almacen_codigo,
                tp.solicitante_usuario_id AS solicitante_id,
                u.username AS solicitante_nombre,
                tp.total_partidas,
                tp.partidas_en_revision,
                tp.partidas_aprobadas,
                tp.partidas_rechazadas,
                tp.created_at,
                tp.updated_at
             FROM tickets_productos tp
             LEFT JOIN empresas e
                ON e.id = tp.empresa_id
               AND e.eliminado_en IS NULL
             LEFT JOIN almacenes a
                ON a.id = tp.almacen_id
               AND a.eliminado_en IS NULL
             LEFT JOIN usuarios u
                ON u.id = tp.solicitante_usuario_id
               AND u.eliminado_en IS NULL
             WHERE ' . $whereSql . '
             ORDER BY tp.created_at DESC, tp.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
            'filters' => $filters,
        ];
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = $this->beginTransaction();

        try {
            $result = $operation();
            $this->commit($ownsTransaction);

            return $result;
        } catch (\Throwable $exception) {
            $this->rollBack($ownsTransaction);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     folio: string,
     *     estado: string,
     *     empresa_id: int|null,
     *     almacen_id: int|null,
     *     fecha_desde: string,
     *     fecha_hasta: string
     * }
     */
    private function normalizeListFilters(array $filters): array
    {
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $allowedStates = [
            'EN_REVISION',
            'RESUELTO_PARCIAL',
            'APROBADO',
            'RECHAZADO',
            'CANCELADO',
        ];

        return [
            'folio' => trim((string) ($filters['folio'] ?? '')),
            'estado' => in_array($estado, $allowedStates, true) ? $estado : '',
            'empresa_id' => $this->positiveIntegerOrNull($filters['empresa_id'] ?? null),
            'almacen_id' => $this->positiveIntegerOrNull($filters['almacen_id'] ?? null),
            'fecha_desde' => $this->dateOrEmpty($filters['fecha_desde'] ?? null),
            'fecha_hasta' => $this->dateOrEmpty($filters['fecha_hasta'] ?? null),
        ];
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function dateOrEmpty(mixed $value): string
    {
        $date = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function activeUserExists(int $userId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM usuarios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function activeCompanyExists(int $companyId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM empresas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $companyId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function warehouseBelongsToCompany(
        int $companyId,
        int $warehouseId
    ): bool {
        return $this->warehouseById($companyId, $warehouseId) !== null;
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string}>
     */
    public function availableCompaniesForUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT e.id, e.codigo, e.nombre
             FROM usuario_empresas ue
             INNER JOIN empresas e
                ON e.id = ue.empresa_id
               AND e.activo = 1
               AND e.eliminado_en IS NULL
             INNER JOIN usuarios u
                ON u.id = ue.usuario_id
               AND u.activo = 1
               AND u.eliminado_en IS NULL
             WHERE ue.usuario_id = :usuario_id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
             ORDER BY e.nombre ASC, e.id ASC'
        );
        $statement->execute(['usuario_id' => $userId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{id: int, empresa_id: int, codigo: string, nombre: string}>
     */
    public function availableWarehousesForUser(int $userId, ?int $companyId = null): array
    {
        $where = [
            'ua.usuario_id = :usuario_id',
            'ua.activo = 1',
            'ua.eliminado_en IS NULL',
        ];
        $params = ['usuario_id' => $userId];

        if ($companyId !== null) {
            $where[] = 'ua.empresa_id = :empresa_id';
            $params['empresa_id'] = $companyId;
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT a.id, a.empresa_id, a.codigo, a.nombre
             FROM usuario_almacenes ua
             INNER JOIN usuario_empresas ue
                ON ue.usuario_id = ua.usuario_id
               AND ue.empresa_id = ua.empresa_id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
             INNER JOIN empresas e
                ON e.id = ua.empresa_id
               AND e.activo = 1
               AND e.eliminado_en IS NULL
             INNER JOIN almacenes a
                ON a.id = ua.almacen_id
               AND a.empresa_id = ua.empresa_id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.nombre ASC, a.nombre ASC, a.id ASC'
        );
        $statement->execute($params);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'empresa_id' => (int) $row['empresa_id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string}>
     */
    public function activeBrands(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT id, codigo, nombre
             FROM marcas
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY nombre ASC, id ASC'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id: int, codigo: string, nombre: string}|null
     */
    public function brandById(int $brandId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, nombre
             FROM marcas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $brandId]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
        ] : null;
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string, es_base: int}>
     */
    public function activeCurrencies(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT id, codigo, nombre, es_base
             FROM monedas
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY es_base DESC, codigo ASC, id ASC'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
                'es_base' => (int) $row['es_base'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string, descripcion: string|null}>
     */
    public function activeSatUnits(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT id, codigo, nombre, descripcion
             FROM unidades_sat
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY codigo ASC, id ASC
             LIMIT 200'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'nombre' => (string) $row['nombre'],
                'descripcion' => $row['descripcion'] === null ? null : (string) $row['descripcion'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id: int, codigo: string, nombre: string, descripcion: string|null}|null
     */
    public function activeSatUnitById(int $unitId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, nombre, descripcion
             FROM unidades_sat
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $unitId]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
            'descripcion' => $row['descripcion'] === null ? null : (string) $row['descripcion'],
        ] : null;
    }

    /**
     * @return array{status: 'found'|'not_found'|'ambiguous', id: int|null}
     */
    public function resolveActiveSatUnit(string $value): array
    {
        $value = $this->normalizeCatalogInput($value);

        if ($value === '') {
            return ['status' => 'not_found', 'id' => null];
        }

        if (preg_match('/^[1-9]\d*$/', $value) === 1) {
            $row = $this->activeSatUnitById((int) $value);

            if ($row !== null) {
                return ['status' => 'found', 'id' => (int) $row['id']];
            }
        }

        $code = $this->catalogInputCode($value);
        $statement = $this->connection->pdo()->prepare(
            'SELECT id
             FROM unidades_sat
             WHERE CAST(codigo AS CHAR CHARACTER SET utf8mb4) = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 2'
        );
        $statement->execute(['codigo' => $code]);
        $rows = $statement->fetchAll();

        if (count($rows) === 1) {
            return ['status' => 'found', 'id' => (int) $rows[0]['id']];
        }
        if (count($rows) > 1) {
            return ['status' => 'ambiguous', 'id' => null];
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT id
             FROM unidades_sat
             WHERE (
                    CAST(nombre AS CHAR CHARACTER SET utf8mb4) = :nombre
                    OR CAST(descripcion AS CHAR CHARACTER SET utf8mb4) = :descripcion
               )
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 2'
        );
        $statement->execute([
            'nombre' => $value,
            'descripcion' => $value,
        ]);
        $rows = $statement->fetchAll();

        if (count($rows) === 1) {
            return ['status' => 'found', 'id' => (int) $rows[0]['id']];
        }

        return [
            'status' => count($rows) > 1 ? 'ambiguous' : 'not_found',
            'id' => null,
        ];
    }

    /**
     * @return list<array{id: int, codigo: string, descripcion: string}>
     */
    public function activeSatKeys(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT id, codigo, descripcion
             FROM claves_sat
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY codigo ASC, id ASC
             LIMIT 200'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'descripcion' => (string) $row['descripcion'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id: int, codigo: string, descripcion: string}|null
     */
    public function activeSatKeyById(int $satKeyId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, descripcion
             FROM claves_sat
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $satKeyId]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'descripcion' => (string) $row['descripcion'],
        ] : null;
    }

    /**
     * @return array{status: 'found'|'not_found'|'ambiguous', id: int|null}
     */
    public function resolveActiveSatKey(string $value): array
    {
        $value = $this->normalizeCatalogInput($value);

        if ($value === '') {
            return ['status' => 'not_found', 'id' => null];
        }

        if (preg_match('/^[1-9]\d*$/', $value) === 1) {
            $row = $this->activeSatKeyById((int) $value);

            if ($row !== null) {
                return ['status' => 'found', 'id' => (int) $row['id']];
            }
        }

        $code = $this->catalogInputCode($value);
        $statement = $this->connection->pdo()->prepare(
            'SELECT id
             FROM claves_sat
             WHERE CAST(codigo AS CHAR CHARACTER SET utf8mb4) = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 2'
        );
        $statement->execute(['codigo' => $code]);
        $rows = $statement->fetchAll();

        if (count($rows) === 1) {
            return ['status' => 'found', 'id' => (int) $rows[0]['id']];
        }
        if (count($rows) > 1) {
            return ['status' => 'ambiguous', 'id' => null];
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT id
             FROM claves_sat
             WHERE CAST(descripcion AS CHAR CHARACTER SET utf8mb4) = :descripcion
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 2'
        );
        $statement->execute(['descripcion' => $value]);
        $rows = $statement->fetchAll();

        if (count($rows) === 1) {
            return ['status' => 'found', 'id' => (int) $rows[0]['id']];
        }

        return [
            'status' => count($rows) > 1 ? 'ambiguous' : 'not_found',
            'id' => null,
        ];
    }

    private function normalizeCatalogInput(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }

    private function catalogInputCode(string $value): string
    {
        $normalized = $this->normalizeCatalogInput($value);

        if (str_contains($normalized, ' - ')) {
            return trim(explode(' - ', $normalized, 2)[0]);
        }

        if (str_contains($normalized, ' · ')) {
            return trim(explode(' · ', $normalized, 2)[0]);
        }

        return $normalized;
    }

    /**
     * @return array{id: int, empresa_id: int, codigo: string}|null
     */
    public function warehouseById(int $companyId, int $warehouseId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, empresa_id, codigo
             FROM almacenes
             WHERE id = :id
               AND empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'id' => $warehouseId,
            'empresa_id' => $companyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
            'codigo' => (string) $row['codigo'],
        ] : null;
    }

    /**
     * @return array{folio_id: int, folio: string, numero: int}
     */
    public function emitProductTicketFolio(
        int $companyId,
        int $warehouseId,
        int $actorId
    ): array {
        $warehouse = $this->warehouseById($companyId, $warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException('Warehouse scope not available.');
        }

        $warehouseCode = strtoupper(trim($warehouse['codigo']));
        $series = $this->findOrCreateProductTicketSeriesForUpdate(
            $companyId,
            $warehouseId,
            $warehouseCode,
            $actorId
        );
        $number = (int) $series['siguiente_numero'];
        $nextNumber = $number + 1;
        $folio = $warehouseCode . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);

        $folioId = $this->insertDocumentoFolio([
            'serie_documental_id' => (int) $series['id'],
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => self::FOLIO_TYPE,
            'codigo_serie' => self::FOLIO_SERIES,
            'prefijo_documento' => $warehouseCode,
            'codigo_almacen_snapshot' => $warehouseCode,
            'formato' => self::FOLIO_FORMAT,
            'folio' => $folio,
            'numero' => $number,
            'anio' => null,
            'documento_tipo_origen' => 'TICKET_PRODUCTO',
            'documento_id_origen' => null,
            'referencia_externa' => null,
            'creado_por_usuario_id' => $actorId,
        ]);

        $this->updateSeriesNextNumber((int) $series['id'], $nextNumber);

        return [
            'folio_id' => $folioId,
            'folio' => $folio,
            'numero' => $number,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTicket(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO tickets_productos (
                folio,
                empresa_id,
                almacen_id,
                solicitante_usuario_id,
                estado,
                observaciones_generales,
                total_partidas,
                partidas_en_revision,
                partidas_aprobadas,
                partidas_rechazadas,
                created_at
             ) VALUES (
                :folio,
                :empresa_id,
                :almacen_id,
                :solicitante_usuario_id,
                :estado,
                :observaciones_generales,
                :total_partidas,
                :partidas_en_revision,
                :partidas_aprobadas,
                :partidas_rechazadas,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'folio' => $data['folio'],
            'empresa_id' => $data['empresa_id'],
            'almacen_id' => $data['almacen_id'],
            'solicitante_usuario_id' => $data['solicitante_usuario_id'],
            'estado' => $data['estado'],
            'observaciones_generales' => $data['observaciones_generales'],
            'total_partidas' => $data['total_partidas'],
            'partidas_en_revision' => $data['partidas_en_revision'],
            'partidas_aprobadas' => $data['partidas_aprobadas'],
            'partidas_rechazadas' => $data['partidas_rechazadas'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPartida(
        int $ticketId,
        int $number,
        array $data
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO tickets_productos_partidas (
                ticket_producto_id,
                numero_partida,
                estado,
                modelo,
                marca_texto,
                descripcion,
                proveedor_id,
                proveedor_texto,
                unidad_sat_id,
                clave_sat_id,
                moneda_id,
                costo_sugerido,
                peso,
                lleva_serie,
                observaciones,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :numero_partida,
                :estado,
                :modelo,
                :marca_texto,
                :descripcion,
                :proveedor_id,
                :proveedor_texto,
                :unidad_sat_id,
                :clave_sat_id,
                :moneda_id,
                :costo_sugerido,
                :peso,
                :lleva_serie,
                :observaciones,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'numero_partida' => $number,
            'estado' => $data['estado'],
            'modelo' => $data['modelo'],
            'marca_texto' => $data['marca_texto'],
            'descripcion' => $data['descripcion'],
            'proveedor_id' => $data['proveedor_id'],
            'proveedor_texto' => $data['proveedor_texto'],
            'unidad_sat_id' => $data['unidad_sat_id'],
            'clave_sat_id' => $data['clave_sat_id'],
            'moneda_id' => $data['moneda_id'],
            'costo_sugerido' => $data['costo_sugerido'],
            'peso' => $data['peso'],
            'lleva_serie' => $data['lleva_serie'],
            'observaciones' => $data['observaciones'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTicketById(int $ticketId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM tickets_productos
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $ticketId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTicketByIdForUpdate(int $ticketId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM tickets_productos
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $ticketId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPartidaByIdForUpdate(int $partidaId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM tickets_productos_partidas
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $partidaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updatePartidaResolution(
        int $partidaId,
        string $state,
        int $actorId,
        ?string $comment,
        ?string $rejectReason
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE tickets_productos_partidas
             SET estado = :estado,
                 resuelto_por_usuario_id = :resuelto_por_usuario_id,
                 resuelto_at = CURRENT_TIMESTAMP,
                 comentario_resolucion = :comentario_resolucion,
                 motivo_rechazo = :motivo_rechazo,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'id' => $partidaId,
            'estado' => $state,
            'resuelto_por_usuario_id' => $actorId,
            'comentario_resolucion' => $comment,
            'motivo_rechazo' => $rejectReason,
        ]);
    }

    /**
     * @return array{total: int, en_revision: int, aprobadas: int, rechazadas: int}
     */
    public function countPartidasByState(int $ticketId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT estado, COUNT(*) AS total
             FROM tickets_productos_partidas
             WHERE ticket_producto_id = :ticket_id
               AND deleted_at IS NULL
             GROUP BY estado'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $counts = [
            'total' => 0,
            'en_revision' => 0,
            'aprobadas' => 0,
            'rechazadas' => 0,
        ];

        foreach ($statement->fetchAll() as $row) {
            $total = (int) $row['total'];
            $counts['total'] += $total;

            if ($row['estado'] === 'EN_REVISION') {
                $counts['en_revision'] = $total;
            } elseif ($row['estado'] === 'APROBADA') {
                $counts['aprobadas'] = $total;
            } elseif ($row['estado'] === 'RECHAZADA') {
                $counts['rechazadas'] = $total;
            }
        }

        return $counts;
    }

    /**
     * @param array{total: int, en_revision: int, aprobadas: int, rechazadas: int} $counts
     */
    public function updateTicketCounters(
        int $ticketId,
        string $state,
        array $counts
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE tickets_productos
             SET estado = :estado,
                 total_partidas = :total_partidas,
                 partidas_en_revision = :partidas_en_revision,
                 partidas_aprobadas = :partidas_aprobadas,
                 partidas_rechazadas = :partidas_rechazadas,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'id' => $ticketId,
            'estado' => $state,
            'total_partidas' => $counts['total'],
            'partidas_en_revision' => $counts['en_revision'],
            'partidas_aprobadas' => $counts['aprobadas'],
            'partidas_rechazadas' => $counts['rechazadas'],
        ]);
    }

    public function cancelTicket(
        int $ticketId,
        string $reason,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE tickets_productos
             SET estado = \'CANCELADO\',
                 cancelado_por_usuario_id = :cancelado_por_usuario_id,
                 cancelado_at = CURRENT_TIMESTAMP,
                 motivo_cancelacion = :motivo_cancelacion,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'id' => $ticketId,
            'cancelado_por_usuario_id' => $actorId,
            'motivo_cancelacion' => $reason,
        ]);
    }

    public function agregarComentario(
        int $ticketId,
        ?int $partidaId,
        int $userId,
        string $comment
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO tickets_productos_comentarios (
                ticket_producto_id,
                partida_id,
                usuario_id,
                comentario,
                visibilidad,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :usuario_id,
                :comentario,
                \'INTERNA\',
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $partidaId,
            'usuario_id' => $userId,
            'comentario' => $comment,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array{
     *     nombre_original: string,
     *     nombre_guardado: string,
     *     ruta_relativa: string,
     *     mime: string,
     *     extension: string,
     *     tamano_bytes: int,
     *     hash_sha256: string
     * } $metadata
     */
    public function agregarAdjunto(
        int $ticketId,
        ?int $partidaId,
        int $userId,
        array $metadata
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO tickets_productos_adjuntos (
                ticket_producto_id,
                partida_id,
                subido_por_usuario_id,
                nombre_original,
                nombre_guardado,
                ruta_relativa,
                mime,
                extension,
                tamano_bytes,
                hash_sha256,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :subido_por_usuario_id,
                :nombre_original,
                :nombre_guardado,
                :ruta_relativa,
                :mime,
                :extension,
                :tamano_bytes,
                :hash_sha256,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $partidaId,
            'subido_por_usuario_id' => $userId,
            'nombre_original' => $metadata['nombre_original'],
            'nombre_guardado' => $metadata['nombre_guardado'],
            'ruta_relativa' => $metadata['ruta_relativa'],
            'mime' => $metadata['mime'],
            'extension' => $metadata['extension'],
            'tamano_bytes' => $metadata['tamano_bytes'],
            'hash_sha256' => $metadata['hash_sha256'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function insertEvent(
        int $ticketId,
        ?int $partidaId,
        ?int $userId,
        string $event,
        ?string $description,
        ?array $metadata = null
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO tickets_productos_eventos (
                ticket_producto_id,
                partida_id,
                usuario_id,
                evento,
                descripcion,
                metadata_json,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :usuario_id,
                :evento,
                :descripcion,
                :metadata_json,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $partidaId,
            'usuario_id' => $userId,
            'evento' => $event,
            'descripcion' => $description,
            'metadata_json' => $metadata === null
                ? null
                : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPartidas(int $ticketId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM tickets_productos_partidas
             WHERE ticket_producto_id = :ticket_id
               AND deleted_at IS NULL
             ORDER BY numero_partida ASC, id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listComentarios(int $ticketId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, ticket_producto_id, partida_id, usuario_id, comentario,
                    visibilidad, created_at
             FROM tickets_productos_comentarios
             WHERE ticket_producto_id = :ticket_id
               AND deleted_at IS NULL
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdjuntos(int $ticketId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, ticket_producto_id, partida_id, subido_por_usuario_id,
                    nombre_original, nombre_guardado, ruta_relativa, mime,
                    extension, tamano_bytes, hash_sha256, created_at
             FROM tickets_productos_adjuntos
             WHERE ticket_producto_id = :ticket_id
               AND deleted_at IS NULL
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEventos(int $ticketId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, ticket_producto_id, partida_id, usuario_id, evento,
                    descripcion, metadata_json, created_at
             FROM tickets_productos_eventos
             WHERE ticket_producto_id = :ticket_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateProductTicketSeriesForUpdate(
        int $companyId,
        int $warehouseId,
        string $warehouseCode,
        int $actorId
    ): array {
        $series = $this->findSeriesForUpdate($companyId, $warehouseId);

        if ($series !== null) {
            return $series;
        }

        $statement = $this->connection->pdo()->prepare(
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
                actualizado_por
             ) VALUES (
                :empresa_id,
                :almacen_id,
                :tipo_documento,
                :codigo_serie,
                :prefijo,
                :codigo_almacen_snapshot,
                :formato,
                :separador,
                10,
                6,
                0,
                NULL,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => self::FOLIO_TYPE,
            'codigo_serie' => self::FOLIO_SERIES,
            'prefijo' => $warehouseCode,
            'codigo_almacen_snapshot' => $warehouseCode,
            'formato' => self::FOLIO_FORMAT,
            'separador' => '-',
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        $series = $this->findSeriesForUpdate($companyId, $warehouseId);

        if ($series === null) {
            throw new \RuntimeException('Product ticket folio series not found.');
        }

        return $series;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSeriesForUpdate(
        int $companyId,
        int $warehouseId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, siguiente_numero
             FROM series_documentales
             WHERE empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND tipo_documento = :tipo_documento
               AND codigo_serie = :codigo_serie
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => self::FOLIO_TYPE,
            'codigo_serie' => self::FOLIO_SERIES,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertDocumentoFolio(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
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
        $statement->execute([
            'serie_documental_id' => $data['serie_documental_id'],
            'empresa_id' => $data['empresa_id'],
            'almacen_id' => $data['almacen_id'],
            'tipo_documento' => $data['tipo_documento'],
            'codigo_serie' => $data['codigo_serie'],
            'prefijo_documento' => $data['prefijo_documento'],
            'codigo_almacen_snapshot' => $data['codigo_almacen_snapshot'],
            'formato' => $data['formato'],
            'folio' => $data['folio'],
            'numero' => $data['numero'],
            'anio' => $data['anio'],
            'documento_tipo_origen' => $data['documento_tipo_origen'],
            'documento_id_origen' => $data['documento_id_origen'],
            'referencia_externa' => $data['referencia_externa'],
            'creado_por_usuario_id' => $data['creado_por_usuario_id'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    private function updateSeriesNextNumber(int $seriesId, int $nextNumber): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE series_documentales
             SET siguiente_numero = :siguiente_numero
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $seriesId,
            'siguiente_numero' => $nextNumber,
        ]);
    }
}

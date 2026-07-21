<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class FolioSeriesRepository
{
    public const FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function paginate(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        [$where, $parameters] = $this->conditions($normalized);
        $limit = (int) $normalized['per_page'];
        $offset = ((int) $normalized['page'] - 1) * $limit;

        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                sd.id,
                sd.empresa_id,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                sd.almacen_id,
                a.nombre AS almacen_nombre,
                a.codigo AS almacen_codigo,
                sd.tipo_documento,
                sd.codigo_serie,
                sd.prefijo,
                sd.codigo_almacen_snapshot,
                sd.formato,
                sd.separador,
                sd.siguiente_numero,
                sd.longitud,
                sd.reinicio_anual,
                sd.anio_actual,
                sd.activo,
                sd.creado_en,
                sd.actualizado_en
            FROM series_documentales sd
            INNER JOIN empresas e ON e.id = sd.empresa_id
            INNER JOIN almacenes a ON a.id = sd.almacen_id
            WHERE
            SQL
            . ' ' . $where
            . ' ORDER BY e.nombre, a.nombre, sd.tipo_documento, sd.codigo_serie LIMIT '
            . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM series_documentales sd
             INNER JOIN empresas e ON e.id = sd.empresa_id
             INNER JOIN almacenes a ON a.id = sd.almacen_id
             WHERE ' . $where
        );
        $count->execute($parameters);

        return [
            'rows' => $rows,
            'total' => (int) $count->fetchColumn(),
            'page' => (int) $normalized['page'],
            'per_page' => $limit,
            'filters' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                sd.id,
                sd.empresa_id,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                sd.almacen_id,
                a.nombre AS almacen_nombre,
                a.codigo AS almacen_codigo,
                sd.tipo_documento,
                sd.codigo_serie,
                sd.prefijo,
                sd.codigo_almacen_snapshot,
                sd.formato,
                sd.separador,
                sd.siguiente_numero,
                sd.longitud,
                sd.reinicio_anual,
                sd.anio_actual,
                sd.activo,
                sd.creado_en,
                sd.actualizado_en,
                (
                    SELECT COUNT(*)
                    FROM documentos_folios df
                    WHERE df.serie_documental_id = sd.id
                ) AS folios_emitidos
            FROM series_documentales sd
            INNER JOIN empresas e ON e.id = sd.empresa_id
            INNER JOIN almacenes a ON a.id = sd.almacen_id
            WHERE sd.id = :id
              AND sd.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO series_documentales (
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
                :siguiente_numero,
                :longitud,
                :reinicio_anual,
                :anio_actual,
                :activo,
                :actor_id,
                :actor_id_update
            )
            SQL
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'almacen_id' => $data['almacen_id'],
            'tipo_documento' => $data['tipo_documento'],
            'codigo_serie' => $data['codigo_serie'],
            'prefijo' => $data['prefijo'],
            'codigo_almacen_snapshot' => $data['codigo_almacen_snapshot'],
            'formato' => $data['formato'],
            'separador' => $data['separador'],
            'siguiente_numero' => $data['siguiente_numero'],
            'longitud' => $data['longitud'],
            'reinicio_anual' => $data['reinicio_anual'],
            'anio_actual' => $data['anio_actual'],
            'activo' => $data['activo'],
            'actor_id' => $data['actor_id'],
            'actor_id_update' => $data['actor_id'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE series_documentales
            SET empresa_id = :empresa_id,
                almacen_id = :almacen_id,
                tipo_documento = :tipo_documento,
                codigo_serie = :codigo_serie,
                prefijo = :prefijo,
                codigo_almacen_snapshot = :codigo_almacen_snapshot,
                formato = :formato,
                separador = :separador,
                siguiente_numero = :siguiente_numero,
                longitud = :longitud,
                reinicio_anual = :reinicio_anual,
                anio_actual = :anio_actual,
                activo = :activo,
                actualizado_por = :actor_id
            WHERE id = :id
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $data['empresa_id'],
            'almacen_id' => $data['almacen_id'],
            'tipo_documento' => $data['tipo_documento'],
            'codigo_serie' => $data['codigo_serie'],
            'prefijo' => $data['prefijo'],
            'codigo_almacen_snapshot' => $data['codigo_almacen_snapshot'],
            'formato' => $data['formato'],
            'separador' => $data['separador'],
            'siguiente_numero' => $data['siguiente_numero'],
            'longitud' => $data['longitud'],
            'reinicio_anual' => $data['reinicio_anual'],
            'anio_actual' => $data['anio_actual'],
            'activo' => $data['activo'],
            'actor_id' => $data['actor_id'],
        ]);
    }

    public function activate(int $id, int $actorId): void
    {
        $this->setActive($id, true, $actorId);
    }

    public function deactivate(int $id, int $actorId): void
    {
        $this->setActive($id, false, $actorId);
    }

    public function existsScope(
        int $empresaId,
        int $almacenId,
        string $tipoDocumento,
        string $codigoSerie,
        ?int $excludeId = null
    ): bool {
        $sql = 'SELECT COUNT(*)
                FROM series_documentales
                WHERE empresa_id = :empresa_id
                  AND almacen_id = :almacen_id
                  AND tipo_documento = :tipo_documento
                  AND codigo_serie = :codigo_serie
                  AND eliminado_en IS NULL';
        $parameters = [
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
            'tipo_documento' => $tipoDocumento,
            'codigo_serie' => $codigoSerie,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    public function companyExists(int $empresaId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM empresas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $empresaId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function warehouseBelongsToCompany(int $empresaId, int $almacenId): bool
    {
        return $this->warehouseById($almacenId, $empresaId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function warehouseById(int $almacenId, ?int $empresaId = null): ?array
    {
        $sql = 'SELECT id, empresa_id, codigo, nombre
                FROM almacenes
                WHERE id = :id
                  AND activo = 1
                  AND eliminado_en IS NULL';
        $parameters = ['id' => $almacenId];

        if ($empresaId !== null) {
            $sql .= ' AND empresa_id = :empresa_id';
            $parameters['empresa_id'] = $empresaId;
        }

        $statement = $this->connection->pdo()->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function companiesForSelect(): array
    {
        return $this->connection->pdo()->query(
            'SELECT id, codigo, nombre
             FROM empresas
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY nombre, codigo'
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function warehousesForSelect(?int $empresaId = null): array
    {
        $sql = 'SELECT id, empresa_id, codigo, nombre
                FROM almacenes
                WHERE activo = 1
                  AND eliminado_en IS NULL';
        $parameters = [];

        if ($empresaId !== null) {
            $sql .= ' AND empresa_id = :empresa_id';
            $parameters['empresa_id'] = $empresaId;
        }

        $statement = $this->connection->pdo()->prepare(
            $sql . ' ORDER BY nombre, codigo'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function hasIssuedFolios(int $serieDocumentalId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM documentos_folios
             WHERE serie_documental_id = :id'
        );
        $statement->execute(['id' => $serieDocumentalId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function preview(string $prefijo, string $snapshot, int $number, int $length): string
    {
        return $prefijo . '-' . $snapshot . str_pad(
            (string) $number,
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $empresaId = filter_var(
            $filters['empresa_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $almacenId = filter_var(
            $filters['almacen_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $page = filter_var(
            $filters['page'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'empresa_id' => $empresaId === false ? null : $empresaId,
            'almacen_id' => $almacenId === false ? null : $almacenId,
            'tipo_documento' => trim((string) ($filters['tipo_documento'] ?? '')),
            'activo' => in_array(($filters['activo'] ?? 'active'), ['active', 'inactive', 'all'], true)
                ? (string) ($filters['activo'] ?? 'active')
                : 'active',
            'page' => $page === false ? 1 : $page,
            'per_page' => 20,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(array $filters): array
    {
        $conditions = ['sd.eliminado_en IS NULL'];
        $parameters = [];

        if ($filters['q'] !== '') {
            $conditions[] = '(sd.tipo_documento LIKE :q
                OR sd.codigo_serie LIKE :q
                OR sd.prefijo LIKE :q
                OR sd.codigo_almacen_snapshot LIKE :q
                OR sd.formato LIKE :q
                OR e.nombre LIKE :q
                OR a.nombre LIKE :q
                OR a.codigo LIKE :q)';
            $parameters['q'] = '%' . $filters['q'] . '%';
        }

        if ($filters['empresa_id'] !== null) {
            $conditions[] = 'sd.empresa_id = :empresa_id';
            $parameters['empresa_id'] = $filters['empresa_id'];
        }

        if ($filters['almacen_id'] !== null) {
            $conditions[] = 'sd.almacen_id = :almacen_id';
            $parameters['almacen_id'] = $filters['almacen_id'];
        }

        if ($filters['tipo_documento'] !== '') {
            $conditions[] = 'sd.tipo_documento = :tipo_documento';
            $parameters['tipo_documento'] = $filters['tipo_documento'];
        }

        if ($filters['activo'] === 'active') {
            $conditions[] = 'sd.activo = 1';
        } elseif ($filters['activo'] === 'inactive') {
            $conditions[] = 'sd.activo = 0';
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    private function setActive(int $id, bool $active, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE series_documentales
             SET activo = :activo,
                 actualizado_por = :actor_id
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'activo' => $active ? 1 : 0,
            'actor_id' => $actorId,
        ]);
    }
}

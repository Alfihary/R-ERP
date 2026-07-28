<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class PriceAuthorizationRepository
{
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
        if ($ownsTransaction && $this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM autorizaciones_precio
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM autorizaciones_precio
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByDocumentLine(
        string $documentoTipo,
        int $documentoId,
        int $documentoPartidaId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM autorizaciones_precio
             WHERE documento_tipo = :documento_tipo
               AND documento_id = :documento_id
               AND documento_partida_id = :documento_partida_id
               AND estatus IN (\'PENDIENTE\', \'APROBADA\')
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'documento_tipo' => $documentoTipo,
            'documento_id' => $documentoId,
            'documento_partida_id' => $documentoPartidaId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByDocumentLineForUpdate(
        string $documentoTipo,
        int $documentoId,
        int $documentoPartidaId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM autorizaciones_precio
             WHERE documento_tipo = :documento_tipo
               AND documento_id = :documento_id
               AND documento_partida_id = :documento_partida_id
               AND estatus IN (\'PENDIENTE\', \'APROBADA\')
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'documento_tipo' => $documentoTipo,
            'documento_id' => $documentoId,
            'documento_partida_id' => $documentoPartidaId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsableByDocumentLine(
        string $documentoTipo,
        int $documentoId,
        int $documentoPartidaId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM autorizaciones_precio
             WHERE documento_tipo = :documento_tipo
               AND documento_id = :documento_id
               AND documento_partida_id = :documento_partida_id
               AND estatus = \'APROBADA\'
               AND utilizado_en IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'documento_tipo' => $documentoTipo,
            'documento_id' => $documentoId,
            'documento_partida_id' => $documentoPartidaId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function insert(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO autorizaciones_precio (
                folio,
                empresa_id,
                documento_tipo,
                documento_id,
                documento_partida_id,
                documento_folio,
                producto_precio_id,
                id_producto,
                lista_precio_id,
                moneda_id,
                precio_lista_referencia,
                precio_minimo_referencia,
                precio_solicitado,
                cantidad,
                incluye_impuestos,
                motivo_solicitud,
                estatus,
                solicitado_por,
                vence_en,
                actualizado_por
             ) VALUES (
                :folio,
                :empresa_id,
                :documento_tipo,
                :documento_id,
                :documento_partida_id,
                :documento_folio,
                :producto_precio_id,
                :id_producto,
                :lista_precio_id,
                :moneda_id,
                :precio_lista_referencia,
                :precio_minimo_referencia,
                :precio_solicitado,
                :cantidad,
                :incluye_impuestos,
                :motivo_solicitud,
                :estatus,
                :solicitado_por,
                :vence_en,
                :actualizado_por
             )'
        );
        $statement->execute([
            'folio' => $data['folio'],
            'empresa_id' => $data['empresa_id'],
            'documento_tipo' => $data['documento_tipo'],
            'documento_id' => $data['documento_id'],
            'documento_partida_id' => $data['documento_partida_id'],
            'documento_folio' => $data['documento_folio'] ?? null,
            'producto_precio_id' => $data['producto_precio_id'],
            'id_producto' => $data['id_producto'],
            'lista_precio_id' => $data['lista_precio_id'],
            'moneda_id' => $data['moneda_id'],
            'precio_lista_referencia' => $data['precio_lista_referencia'],
            'precio_minimo_referencia' => $data['precio_minimo_referencia'],
            'precio_solicitado' => $data['precio_solicitado'],
            'cantidad' => $data['cantidad'],
            'incluye_impuestos' => $data['incluye_impuestos'],
            'motivo_solicitud' => $data['motivo_solicitud'],
            'estatus' => $data['estatus'] ?? 'PENDIENTE',
            'solicitado_por' => $data['solicitado_por'],
            'vence_en' => $data['vence_en'] ?? null,
            'actualizado_por' => $data['actualizado_por'] ?? null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(int $id, array $data): void
    {
        $allowed = [
            'estatus',
            'decidido_en',
            'decidido_por',
            'comentario_decision',
            'cancelado_en',
            'cancelado_por',
            'motivo_cancelacion',
            'utilizado_en',
            'utilizado_por',
            'actualizado_en',
            'actualizado_por',
        ];
        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $data[$column];
            }
        }

        if ($sets === []) {
            return;
        }

        $statement = $this->connection->pdo()->prepare(
            'UPDATE autorizaciones_precio
             SET ' . implode(', ', $sets) . '
             WHERE id = :id'
        );
        $statement->execute($params);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = $this->where($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT ap.*, lp.clave AS lista_clave, m.codigo AS moneda_codigo,
                    p.descripcion AS producto_descripcion,
                    us.username AS solicitado_por_username,
                    ud.username AS decidido_por_username,
                    uc.username AS cancelado_por_username,
                    uu.username AS utilizado_por_username
             FROM autorizaciones_precio ap
             INNER JOIN listas_precios lp ON lp.id = ap.lista_precio_id
             INNER JOIN monedas m ON m.id = ap.moneda_id
             INNER JOIN productos p ON p.id_producto = ap.id_producto
             INNER JOIN usuarios us ON us.id = ap.solicitado_por
             LEFT JOIN usuarios ud ON ud.id = ap.decidido_por
             LEFT JOIN usuarios uc ON uc.id = ap.cancelado_por
             LEFT JOIN usuarios uu ON uu.id = ap.utilizado_por
             ' . $where['sql'] . '
             ORDER BY ap.solicitado_en DESC, ap.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($where['params'] as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue('limit', max(1, min(100, $perPage)), PDO::PARAM_INT);
        $statement->bindValue(
            'offset',
            (max(1, $page) - 1) * max(1, min(100, $perPage)),
            PDO::PARAM_INT
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        $where = $this->where($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM autorizaciones_precio ap
             ' . $where['sql']
        );
        $statement->execute($where['params']);

        return (int) $statement->fetchColumn();
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

    /**
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<string, int|string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach ([
            'estatus',
            'documento_tipo',
            'id_producto',
        ] as $field) {
            if (isset($filters[$field]) && trim((string) $filters[$field]) !== '') {
                $conditions[] = 'ap.' . $field . ' = :' . $field;
                $params[$field] = trim((string) $filters[$field]);
            }
        }

        foreach ([
            'documento_id',
            'documento_partida_id',
            'lista_precio_id',
            'solicitado_por',
            'decidido_por',
            'empresa_id',
        ] as $field) {
            if (isset($filters[$field]) && (int) $filters[$field] > 0) {
                $conditions[] = 'ap.' . $field . ' = :' . $field;
                $params[$field] = (int) $filters[$field];
            }
        }

        if (isset($filters['utilizado'])) {
            if ((string) $filters['utilizado'] === 'yes') {
                $conditions[] = 'ap.utilizado_en IS NOT NULL';
            } elseif ((string) $filters['utilizado'] === 'no') {
                $conditions[] = 'ap.utilizado_en IS NULL';
            }
        }

        if (isset($filters['fecha_desde']) && trim((string) $filters['fecha_desde']) !== '') {
            $conditions[] = 'ap.solicitado_en >= :fecha_desde';
            $params['fecha_desde'] = trim((string) $filters['fecha_desde']) . ' 00:00:00';
        }

        if (isset($filters['fecha_hasta']) && trim((string) $filters['fecha_hasta']) !== '') {
            $conditions[] = 'ap.solicitado_en <= :fecha_hasta';
            $params['fecha_hasta'] = trim((string) $filters['fecha_hasta']) . ' 23:59:59';
        }

        return [
            'sql' => $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
        ];
    }
}

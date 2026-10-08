<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class SolicitudCotizacionRepository
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

    public function commit(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->connection->pdo()->commit();
        }
    }

    public function rollBack(bool $ownsTransaction): void
    {
        if ($ownsTransaction && $this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }
    }

    /** @return array<string,mixed>|null */
    public function findBySolicitudIdForUpdate(string $solicitudId): ?array
    {
        $q = $this->connection->pdo()->prepare(
            'SELECT * FROM solicitudes_cotizacion WHERE solicitud_id = :solicitud_id LIMIT 1 FOR UPDATE'
        );
        $q->execute(['solicitud_id' => $solicitudId]);
        $row = $q->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): array
    {
        $temporaryFolio = 'TMP-' . str_replace('-', '', $data['solicitud_id']);
        $q = $this->connection->pdo()->prepare(<<<'SQL'
            INSERT INTO solicitudes_cotizacion (
                solicitud_id, folio, nombre_cliente, telefono, correo, solicitud,
                vendedor, vendedor_usuario_id, estado, producto_id_externo,
                producto_codigo, producto_descripcion, producto_marca,
                producto_unidad, match_score, match_motivo, created_at
            ) VALUES (
                :solicitud_id, :folio, :nombre_cliente, :telefono, :correo, :solicitud,
                :vendedor, :vendedor_usuario_id, :estado, :producto_id_externo,
                :producto_codigo, :producto_descripcion, :producto_marca,
                :producto_unidad, :match_score, :match_motivo, CURRENT_TIMESTAMP
            )
            SQL);
        $q->execute([
            'solicitud_id' => $data['solicitud_id'], 'folio' => $temporaryFolio,
            'nombre_cliente' => $data['nombre_cliente'], 'telefono' => $data['telefono'],
            'correo' => $data['correo'], 'solicitud' => $data['solicitud'],
            'vendedor' => $data['vendedor'], 'vendedor_usuario_id' => $data['vendedor_usuario_id'],
            'estado' => $data['estado'], 'producto_id_externo' => $data['producto_id_externo'],
            'producto_codigo' => $data['producto_codigo'], 'producto_descripcion' => $data['producto_descripcion'],
            'producto_marca' => $data['producto_marca'], 'producto_unidad' => $data['producto_unidad'],
            'match_score' => $data['match_score'], 'match_motivo' => $data['match_motivo'],
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $folio = 'SC-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $u = $this->connection->pdo()->prepare(
            'UPDATE solicitudes_cotizacion SET folio = :folio WHERE id = :id'
        );
        $u->execute(['folio' => $folio, 'id' => $id]);
        return $this->findById($id) ?? throw new \RuntimeException('Solicitud no creada.');
    }

    /** @param array<string,mixed> $data */
    public function updateExisting(int $id, array $data): array
    {
        $q = $this->connection->pdo()->prepare(<<<'SQL'
            UPDATE solicitudes_cotizacion SET
                nombre_cliente = :nombre_cliente, telefono = :telefono, correo = :correo,
                solicitud = :solicitud, vendedor = :vendedor,
                vendedor_usuario_id = :vendedor_usuario_id, estado = :estado,
                producto_id_externo = :producto_id_externo, producto_codigo = :producto_codigo,
                producto_descripcion = :producto_descripcion, producto_marca = :producto_marca,
                producto_unidad = :producto_unidad, match_score = :match_score,
                match_motivo = :match_motivo, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL);
        $q->execute([
            'id' => $id,
            'nombre_cliente' => $data['nombre_cliente'],
            'telefono' => $data['telefono'],
            'correo' => $data['correo'],
            'solicitud' => $data['solicitud'],
            'vendedor' => $data['vendedor'],
            'vendedor_usuario_id' => $data['vendedor_usuario_id'],
            'estado' => $data['estado'],
            'producto_id_externo' => $data['producto_id_externo'],
            'producto_codigo' => $data['producto_codigo'],
            'producto_descripcion' => $data['producto_descripcion'],
            'producto_marca' => $data['producto_marca'],
            'producto_unidad' => $data['producto_unidad'],
            'match_score' => $data['match_score'],
            'match_motivo' => $data['match_motivo'],
        ]);
        return $this->findById($id) ?? throw new \RuntimeException('Solicitud no encontrada.');
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $q = $this->connection->pdo()->prepare('SELECT * FROM solicitudes_cotizacion WHERE id = :id LIMIT 1');
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        $q = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT s.*
            FROM solicitudes_cotizacion s
            INNER JOIN usuarios u ON u.id = :user_id AND u.activo = 1 AND u.deleted_at IS NULL
            INNER JOIN usuario_rol ur ON ur.usuario_id = u.id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            WHERE s.id = :id
              AND s.vendedor_usuario_id = u.id
            LIMIT 1
            SQL);
        $q->execute(['id' => $id, 'user_id' => $userId]);
        $row = $q->fetch();
        return $row === false ? null : $row;
    }

    public function resolveExactVendedorUserId(int $candidateId, ?string $vendedor): ?int
    {
        if ($vendedor === null || trim($vendedor) === '') {
            return null;
        }
        $q = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT u.id
            FROM usuarios u
            WHERE u.id = :id
              AND u.activo = 1
              AND u.deleted_at IS NULL
              AND TRIM(CONCAT_WS(' ', u.nombre, u.nombre_2, u.apellido_paterno, u.apellido_materno)) = :vendedor
            LIMIT 1
            SQL);
        $q->execute(['id' => $candidateId, 'vendedor' => trim($vendedor)]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}

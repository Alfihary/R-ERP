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

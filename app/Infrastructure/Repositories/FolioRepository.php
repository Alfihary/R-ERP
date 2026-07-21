<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class FolioRepository
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
    public function findActiveSeriesForUpdate(
        int $empresaId,
        int $almacenId,
        string $tipoDocumento,
        string $codigoSerie
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
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
            FROM series_documentales
            WHERE empresa_id = :empresa_id
              AND almacen_id = :almacen_id
              AND tipo_documento = :tipo_documento
              AND codigo_serie = :codigo_serie
            LIMIT 1
            FOR UPDATE
            SQL
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
            'tipo_documento' => $tipoDocumento,
            'codigo_serie' => $codigoSerie,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function warehouseBelongsToCompany(int $empresaId, int $almacenId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM almacenes
             WHERE id = :almacen_id
               AND empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function userExists(int $userId): bool
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

    /**
     * @param array<string, int|string|null> $data
     */
    public function insertDocumentoFolio(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO documentos_folios (
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
            )
            SQL
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

    public function updateSerieNextNumber(
        int $serieId,
        int $nextNumber,
        ?int $anioActual
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE series_documentales
             SET siguiente_numero = :siguiente_numero,
                 anio_actual = :anio_actual
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $serieId,
            'siguiente_numero' => $nextNumber,
            'anio_actual' => $anioActual,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFolioById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id AS folio_id,
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
            WHERE id = :id
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function nextFolioId(): int
    {
        $statement = $this->connection->pdo()->query(
            'SELECT COALESCE(MAX(id), 0) + 1 FROM documentos_folios'
        );

        return (int) $statement->fetchColumn();
    }
}

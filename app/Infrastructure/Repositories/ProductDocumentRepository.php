<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductDocumentRepository
{
    public const TYPE_MAIN_PHOTO = 'FOTO_PRINCIPAL';

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeMainPhoto(string $productId): ?array
    {
        return $this->fetchActiveMainPhoto($productId, false);
    }

    public function lockProduct(string $productId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id_producto
             FROM productos
             WHERE id_producto = :id_producto
               AND eliminado_en IS NULL
             FOR UPDATE'
        );
        $statement->execute(['id_producto' => $productId]);

        return $statement->fetch() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockActiveMainPhoto(string $productId): ?array
    {
        return $this->fetchActiveMainPhoto($productId, true);
    }

    /**
     * @param array{
     *     id_producto: string,
     *     nombre_original: string,
     *     ruta_relativa: string,
     *     mime_type: string,
     *     tamano_bytes: int
     * } $data
     */
    public function insertMainPhoto(array $data, int $actorId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO producto_documentos (
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes,
                es_principal,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                :tipo_documento,
                :nombre_original,
                :ruta_relativa,
                :mime_type,
                :tamano_bytes,
                1,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute([
            'id_producto' => $data['id_producto'],
            'tipo_documento' => self::TYPE_MAIN_PHOTO,
            'nombre_original' => $data['nombre_original'],
            'ruta_relativa' => $data['ruta_relativa'],
            'mime_type' => $data['mime_type'],
            'tamano_bytes' => $data['tamano_bytes'],
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function deactivateActiveMainPhotosExcept(
        string $productId,
        int $documentId,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE producto_documentos
            SET activo = 0,
                es_principal = 0,
                eliminado_en = CURRENT_TIMESTAMP,
                eliminado_por = :eliminado_por,
                actualizado_por = :actualizado_por
            WHERE id_producto = :id_producto
              AND tipo_documento = :tipo_documento
              AND es_principal = 1
              AND activo = 1
              AND id <> :document_id
            SQL
        );
        $statement->execute([
            'eliminado_por' => $actorId,
            'actualizado_por' => $actorId,
            'id_producto' => $productId,
            'tipo_documento' => self::TYPE_MAIN_PHOTO,
            'document_id' => $documentId,
        ]);
    }

    public function deactivateMainPhoto(int $documentId, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE producto_documentos
            SET activo = 0,
                es_principal = 0,
                eliminado_en = CURRENT_TIMESTAMP,
                eliminado_por = :eliminado_por,
                actualizado_por = :actualizado_por
            WHERE id = :document_id
              AND tipo_documento = :tipo_documento
              AND activo = 1
            SQL
        );
        $statement->execute([
            'eliminado_por' => $actorId,
            'actualizado_por' => $actorId,
            'document_id' => $documentId,
            'tipo_documento' => self::TYPE_MAIN_PHOTO,
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchActiveMainPhoto(string $productId, bool $lock): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes,
                es_principal,
                activo,
                creado_en,
                actualizado_en
            FROM producto_documentos
            WHERE id_producto = :id_producto
              AND tipo_documento = :tipo_documento
              AND es_principal = 1
              AND activo = 1
              AND eliminado_en IS NULL
            ORDER BY id DESC
            LIMIT 1
            SQL
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'id_producto' => $productId,
            'tipo_documento' => self::TYPE_MAIN_PHOTO,
        ]);
        $photo = $statement->fetch(PDO::FETCH_ASSOC);

        return $photo === false ? null : $photo;
    }
}

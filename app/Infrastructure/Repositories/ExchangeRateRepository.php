<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ExchangeRateRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->connection->pdo()->query(
            <<<'SQL'
            SELECT
                tc.id,
                tc.moneda_origen_id,
                origen.codigo AS moneda_origen_codigo,
                origen.nombre AS moneda_origen_nombre,
                tc.moneda_destino_id,
                destino.codigo AS moneda_destino_codigo,
                destino.nombre AS moneda_destino_nombre,
                tc.fecha,
                tc.valor,
                tc.activo
            FROM tipos_cambio tc
            INNER JOIN monedas origen
                ON origen.id = tc.moneda_origen_id
            INNER JOIN monedas destino
                ON destino.id = tc.moneda_destino_id
            WHERE tc.eliminado_en IS NULL
            ORDER BY tc.fecha DESC, origen.codigo, destino.codigo
            SQL
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeCurrencies(): array
    {
        return $this->connection->pdo()->query(
            <<<'SQL'
            SELECT id, codigo, nombre
            FROM monedas
            WHERE activo = 1
              AND eliminado_en IS NULL
            ORDER BY codigo
            SQL
        )->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
                moneda_origen_id,
                moneda_destino_id,
                fecha,
                valor,
                activo
            FROM tipos_cambio
            WHERE id = :id
              AND eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    public function activeCurrencyExists(int $id): bool
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM monedas
            WHERE id = :id
              AND activo = 1
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function combinationExists(
        int $originId,
        int $destinationId,
        string $date,
        ?int $exceptId = null
    ): bool {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM tipos_cambio
            WHERE moneda_origen_id = :origin_id
              AND moneda_destino_id = :destination_id
              AND fecha = :date
            SQL;
        $parameters = [
            'origin_id' => $originId,
            'destination_id' => $destinationId,
            'date' => $date,
        ];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array{
     *     moneda_origen_id: int,
     *     moneda_destino_id: int,
     *     fecha: string,
     *     valor: string
     * } $data
     */
    public function create(array $data, int $actorId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO tipos_cambio (
                moneda_origen_id,
                moneda_destino_id,
                fecha,
                valor,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :moneda_origen_id,
                :moneda_destino_id,
                :fecha,
                :valor,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute($data + [
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array{
     *     moneda_origen_id: int,
     *     moneda_destino_id: int,
     *     fecha: string,
     *     valor: string
     * } $data
     */
    public function update(int $id, array $data, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE tipos_cambio
            SET moneda_origen_id = :moneda_origen_id,
                moneda_destino_id = :moneda_destino_id,
                fecha = :fecha,
                valor = :valor,
                actualizado_por = :actualizado_por
            WHERE id = :id
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute($data + [
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE tipos_cambio
            SET activo = :activo,
                actualizado_por = :actualizado_por
            WHERE id = :id
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);
    }
}

<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

if (!isset($initialAdminUsername, $initialAdminEmail)
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException(
        'DB-CATALOGOS-1 seed requires the configured initial administrator.'
    );
}

return new class($initialAdminUsername, $initialAdminEmail) implements Seed {
    private const CURRENCIES = [
        ['MXN', 'Peso mexicano', '$', 2, 1],
        ['USD', 'Dólar estadounidense', 'US$', 2, 0],
        ['EUR', 'Euro', '€', 2, 0],
    ];

    private const UNITS = [
        ['PIEZA', 'Pieza', 'pza'],
        ['KG', 'Kilogramo', 'kg'],
        ['LITRO', 'Litro', 'L'],
        ['METRO', 'Metro', 'm'],
        ['SERVICIO', 'Servicio', 'serv'],
    ];

    private const TAXES = [
        ['IVA_16', 'IVA 16%', '16.0000', 'IVA'],
        ['IVA_0', 'IVA 0%', '0.0000', 'IVA'],
        ['EXENTO', 'Exento', '0.0000', 'EXENTO'],
    ];

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminEmail
    ) {
    }

    public function id(): string
    {
        return 'db_catalogos_1_seed_base_catalogs';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $actorId = $this->initialAdminId($pdo);
            $clearBase = $pdo->prepare(
                'UPDATE monedas
                 SET es_base = 0,
                     actualizado_por = :actor_id
                 WHERE es_base = 1 AND codigo <> :codigo'
            );
            $clearBase->execute([
                'actor_id' => $actorId,
                'codigo' => 'MXN',
            ]);

            foreach (self::CURRENCIES as $currency) {
                $this->upsertCurrency($pdo, $actorId, ...$currency);
            }

            foreach (self::UNITS as $unit) {
                $this->upsertUnit($pdo, $actorId, ...$unit);
            }

            foreach (self::TAXES as $tax) {
                $this->upsertTax($pdo, $actorId, ...$tax);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function rollback(PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM impuestos
             WHERE codigo IN ('IVA_16', 'IVA_0', 'EXENTO')"
        );
        $pdo->exec(
            "DELETE FROM unidades_medida
             WHERE codigo IN ('PIEZA', 'KG', 'LITRO', 'METRO', 'SERVICIO')"
        );
        $pdo->exec(
            "DELETE FROM monedas
             WHERE codigo IN ('MXN', 'USD', 'EUR')
               AND NOT EXISTS (
                   SELECT 1
                   FROM tipos_cambio
                   WHERE tipos_cambio.moneda_origen_id = monedas.id
                      OR tipos_cambio.moneda_destino_id = monedas.id
               )"
        );
    }

    private function initialAdminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM usuarios
             WHERE username = :username
               AND email = :email
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'username' => strtolower(trim($this->adminUsername)),
            'email' => strtolower(trim($this->adminEmail)),
        ]);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 requires the active configured initial administrator.'
            );
        }

        return (int) $userId;
    }

    private function upsertCurrency(
        PDO $pdo,
        int $actorId,
        string $code,
        string $name,
        string $symbol,
        int $decimals,
        int $isBase
    ): void {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO monedas (
                codigo,
                nombre,
                simbolo,
                decimales,
                es_base,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :codigo,
                :nombre,
                :simbolo,
                :decimales,
                :es_base,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                simbolo = VALUES(simbolo),
                decimales = VALUES(decimales),
                es_base = VALUES(es_base),
                activo = 1,
                actualizado_por = VALUES(actualizado_por),
                eliminado_en = NULL,
                eliminado_por = NULL
            SQL
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'simbolo' => $symbol,
            'decimales' => $decimals,
            'es_base' => $isBase,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function upsertUnit(
        PDO $pdo,
        int $actorId,
        string $code,
        string $name,
        string $abbreviation
    ): void {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO unidades_medida (
                codigo,
                nombre,
                abreviatura,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :codigo,
                :nombre,
                :abreviatura,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                abreviatura = VALUES(abreviatura),
                activo = 1,
                actualizado_por = VALUES(actualizado_por),
                eliminado_en = NULL,
                eliminado_por = NULL
            SQL
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'abreviatura' => $abbreviation,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function upsertTax(
        PDO $pdo,
        int $actorId,
        string $code,
        string $name,
        string $rate,
        string $type
    ): void {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO impuestos (
                codigo,
                nombre,
                tasa,
                tipo,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :codigo,
                :nombre,
                :tasa,
                :tipo,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                tasa = VALUES(tasa),
                tipo = VALUES(tipo),
                activo = 1,
                actualizado_por = VALUES(actualizado_por),
                eliminado_en = NULL,
                eliminado_por = NULL
            SQL
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'tasa' => $rate,
            'tipo' => $type,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }
};

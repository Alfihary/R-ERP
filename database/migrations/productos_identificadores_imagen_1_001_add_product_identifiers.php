<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_identificadores_imagen_1_001_add_product_identifiers';
    }

    public function up(PDO $pdo): void
    {
        $prerequisite = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $prerequisite->execute([
            'migration' => 'db_productos_2_001_extend_product_master',
        ]);

        if ((int) $prerequisite->fetchColumn() !== 1) {
            throw new RuntimeException(
                'PRODUCTOS-IDENTIFICADORES-IMAGEN-1 requires DB-PRODUCTOS-2.'
            );
        }

        $existingColumns = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name IN (
                   'sku',
                   'sku_alterno',
                   'upc',
                   'ean',
                   'gtin',
                   'codigo_fabricante',
                   'modelo'
               )"
        )->fetchColumn();

        if ($existingColumns !== 0) {
            throw new RuntimeException(
                'PRODUCTOS-IDENTIFICADORES-IMAGEN-1 requires identifier columns to be absent.'
            );
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE productos
                ADD COLUMN sku VARCHAR(40)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER descripcion_larga,
                ADD COLUMN sku_alterno VARCHAR(40)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER sku,
                ADD COLUMN upc VARCHAR(14)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER sku_alterno,
                ADD COLUMN ean VARCHAR(14)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER upc,
                ADD COLUMN gtin VARCHAR(14)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER ean,
                ADD COLUMN codigo_fabricante VARCHAR(60)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER gtin,
                ADD COLUMN modelo VARCHAR(80)
                    NULL
                    AFTER codigo_fabricante,
                ADD UNIQUE KEY uq_productos_sku (sku),
                ADD KEY idx_productos_sku_alterno (sku_alterno),
                ADD KEY idx_productos_upc (upc),
                ADD KEY idx_productos_ean (ean),
                ADD KEY idx_productos_gtin (gtin),
                ADD KEY idx_productos_codigo_fabricante (codigo_fabricante),
                ADD KEY idx_productos_modelo (modelo),
                ADD CONSTRAINT chk_productos_sku CHECK (
                    sku IS NULL
                    OR REGEXP_LIKE(sku, '^[A-Z0-9._/-]{1,40}$', 'c')
                ),
                ADD CONSTRAINT chk_productos_sku_alterno CHECK (
                    sku_alterno IS NULL
                    OR REGEXP_LIKE(sku_alterno, '^[A-Z0-9._/-]{1,40}$', 'c')
                ),
                ADD CONSTRAINT chk_productos_upc CHECK (
                    upc IS NULL
                    OR REGEXP_LIKE(upc, '^[0-9]{12}$', 'c')
                ),
                ADD CONSTRAINT chk_productos_ean CHECK (
                    ean IS NULL
                    OR REGEXP_LIKE(ean, '^(?:[0-9]{8}|[0-9]{13})$', 'c')
                ),
                ADD CONSTRAINT chk_productos_gtin CHECK (
                    gtin IS NULL
                    OR REGEXP_LIKE(
                        gtin,
                        '^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$',
                        'c'
                    )
                ),
                ADD CONSTRAINT chk_productos_codigo_fabricante CHECK (
                    codigo_fabricante IS NULL
                    OR REGEXP_LIKE(
                        codigo_fabricante,
                        '^[A-Z0-9._/-]{1,60}$',
                        'c'
                    )
                ),
                ADD CONSTRAINT chk_productos_modelo CHECK (
                    modelo IS NULL OR CHAR_LENGTH(modelo) BETWEEN 1 AND 80
                )
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $hasSku = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name = 'sku'"
        )->fetchColumn();

        if ($hasSku !== 1) {
            return;
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE productos
                DROP CHECK chk_productos_modelo,
                DROP CHECK chk_productos_codigo_fabricante,
                DROP CHECK chk_productos_gtin,
                DROP CHECK chk_productos_ean,
                DROP CHECK chk_productos_upc,
                DROP CHECK chk_productos_sku_alterno,
                DROP CHECK chk_productos_sku,
                DROP INDEX idx_productos_modelo,
                DROP INDEX idx_productos_codigo_fabricante,
                DROP INDEX idx_productos_gtin,
                DROP INDEX idx_productos_ean,
                DROP INDEX idx_productos_upc,
                DROP INDEX idx_productos_sku_alterno,
                DROP INDEX uq_productos_sku,
                DROP COLUMN modelo,
                DROP COLUMN codigo_fabricante,
                DROP COLUMN gtin,
                DROP COLUMN ean,
                DROP COLUMN upc,
                DROP COLUMN sku_alterno,
                DROP COLUMN sku
            SQL
        );
    }
};

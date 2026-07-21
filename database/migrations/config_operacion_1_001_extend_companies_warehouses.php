<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'config_operacion_1_001_extend_companies_warehouses';
    }

    public function up(PDO $pdo): void
    {
        $scopeMigration = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $scopeMigration->execute([
            'migration' => 'db_scope_1_001_create_scope_tables',
        ]);

        if ((int) $scopeMigration->fetchColumn() !== 1) {
            throw new RuntimeException(
                'CONFIG-OPERACION-EMPRESAS-ALMACENES-1 requires DB-SCOPE-1.'
            );
        }

        foreach ($this->upStatements() as $statement) {
            $pdo->exec($statement);
        }
    }

    public function down(PDO $pdo): void
    {
        foreach ($this->downStatements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function upStatements(): array
    {
        return [
            <<<'SQL'
            ALTER TABLE empresas
                ADD COLUMN razon_social VARCHAR(180) NULL AFTER nombre,
                ADD COLUMN nombre_comercial VARCHAR(180) NULL AFTER razon_social,
                ADD COLUMN rfc VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER nombre_comercial,
                ADD COLUMN regimen_fiscal VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rfc,
                ADD COLUMN telefono VARCHAR(30) NULL AFTER regimen_fiscal,
                ADD COLUMN email VARCHAR(120) NULL AFTER telefono,
                ADD COLUMN sitio_web VARCHAR(160) NULL AFTER email,
                ADD COLUMN pais VARCHAR(80) NULL AFTER sitio_web,
                ADD COLUMN estado VARCHAR(80) NULL AFTER pais,
                ADD COLUMN municipio VARCHAR(80) NULL AFTER estado,
                ADD COLUMN colonia VARCHAR(120) NULL AFTER municipio,
                ADD COLUMN calle VARCHAR(160) NULL AFTER colonia,
                ADD COLUMN numero_exterior VARCHAR(30) NULL AFTER calle,
                ADD COLUMN numero_interior VARCHAR(30) NULL AFTER numero_exterior,
                ADD COLUMN codigo_postal VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER numero_interior,
                ADD COLUMN logo_path VARCHAR(255) NULL AFTER codigo_postal,
                ADD COLUMN color_primario VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER logo_path,
                ADD KEY idx_empresas_rfc (rfc),
                ADD CONSTRAINT chk_empresas_rfc CHECK (
                    rfc IS NULL OR rfc REGEXP _ascii'^[A-Z&0-9]{12,13}$'
                ),
                ADD CONSTRAINT chk_empresas_email CHECK (
                    email IS NULL OR email REGEXP '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                ),
                ADD CONSTRAINT chk_empresas_codigo_postal CHECK (
                    codigo_postal IS NULL OR codigo_postal REGEXP _ascii'^[0-9]{5,10}$'
                ),
                ADD CONSTRAINT chk_empresas_color_primario CHECK (
                    color_primario IS NULL OR color_primario REGEXP _ascii'^#[0-9A-Fa-f]{6}$'
                )
            SQL,
            <<<'SQL'
            ALTER TABLE almacenes
                ADD COLUMN tipo_almacen VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'GENERAL' AFTER nombre,
                ADD COLUMN responsable VARCHAR(160) NULL AFTER tipo_almacen,
                ADD COLUMN telefono VARCHAR(30) NULL AFTER responsable,
                ADD COLUMN email VARCHAR(120) NULL AFTER telefono,
                ADD COLUMN pais VARCHAR(80) NULL AFTER email,
                ADD COLUMN estado VARCHAR(80) NULL AFTER pais,
                ADD COLUMN municipio VARCHAR(80) NULL AFTER estado,
                ADD COLUMN colonia VARCHAR(120) NULL AFTER municipio,
                ADD COLUMN calle VARCHAR(160) NULL AFTER colonia,
                ADD COLUMN numero_exterior VARCHAR(30) NULL AFTER calle,
                ADD COLUMN numero_interior VARCHAR(30) NULL AFTER numero_exterior,
                ADD COLUMN codigo_postal VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER numero_interior,
                ADD COLUMN permite_ventas TINYINT(1) NOT NULL DEFAULT 1 AFTER codigo_postal,
                ADD COLUMN permite_compras TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ventas,
                ADD COLUMN permite_inventario TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_compras,
                ADD COLUMN permite_transferencias TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_inventario,
                ADD COLUMN es_principal TINYINT(1) NOT NULL DEFAULT 0 AFTER permite_transferencias,
                ADD COLUMN principal_empresa_id BIGINT UNSIGNED GENERATED ALWAYS AS (
                    CASE WHEN es_principal = 1 THEN empresa_id ELSE NULL END
                ) STORED AFTER es_principal,
                ADD UNIQUE KEY uq_almacenes_principal_empresa (principal_empresa_id),
                ADD KEY idx_almacenes_tipo (tipo_almacen),
                ADD CONSTRAINT chk_almacenes_tipo CHECK (
                    tipo_almacen IN (
                        _ascii'GENERAL',
                        _ascii'REFACCIONES',
                        _ascii'SERVICIO',
                        _ascii'CUARENTENA',
                        _ascii'DEVOLUCIONES',
                        _ascii'TRANSITO',
                        _ascii'VIRTUAL'
                    )
                ),
                ADD CONSTRAINT chk_almacenes_email CHECK (
                    email IS NULL OR email REGEXP '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                ),
                ADD CONSTRAINT chk_almacenes_codigo_postal CHECK (
                    codigo_postal IS NULL OR codigo_postal REGEXP _ascii'^[0-9]{5,10}$'
                ),
                ADD CONSTRAINT chk_almacenes_flags CHECK (
                    permite_ventas IN (0, 1)
                    AND permite_compras IN (0, 1)
                    AND permite_inventario IN (0, 1)
                    AND permite_transferencias IN (0, 1)
                    AND es_principal IN (0, 1)
                )
            SQL,
            <<<'SQL'
            UPDATE almacenes a
            INNER JOIN (
                SELECT empresa_id, MIN(id) AS principal_id
                FROM almacenes
                WHERE activo = 1 AND eliminado_en IS NULL
                GROUP BY empresa_id
            ) p
                ON p.empresa_id = a.empresa_id
               AND p.principal_id = a.id
            SET a.es_principal = 1
            SQL,
        ];
    }

    /**
     * @return list<string>
     */
    private function downStatements(): array
    {
        return [
            'ALTER TABLE almacenes DROP CHECK chk_almacenes_flags',
            'ALTER TABLE almacenes DROP CHECK chk_almacenes_codigo_postal',
            'ALTER TABLE almacenes DROP CHECK chk_almacenes_email',
            'ALTER TABLE almacenes DROP CHECK chk_almacenes_tipo',
            'ALTER TABLE almacenes DROP INDEX idx_almacenes_tipo',
            'ALTER TABLE almacenes DROP INDEX uq_almacenes_principal_empresa',
            'ALTER TABLE almacenes
                DROP COLUMN principal_empresa_id,
                DROP COLUMN es_principal,
                DROP COLUMN permite_transferencias,
                DROP COLUMN permite_inventario,
                DROP COLUMN permite_compras,
                DROP COLUMN permite_ventas,
                DROP COLUMN codigo_postal,
                DROP COLUMN numero_interior,
                DROP COLUMN numero_exterior,
                DROP COLUMN calle,
                DROP COLUMN colonia,
                DROP COLUMN municipio,
                DROP COLUMN estado,
                DROP COLUMN pais,
                DROP COLUMN email,
                DROP COLUMN telefono,
                DROP COLUMN responsable,
                DROP COLUMN tipo_almacen',
            'ALTER TABLE empresas DROP CHECK chk_empresas_color_primario',
            'ALTER TABLE empresas DROP CHECK chk_empresas_codigo_postal',
            'ALTER TABLE empresas DROP CHECK chk_empresas_email',
            'ALTER TABLE empresas DROP CHECK chk_empresas_rfc',
            'ALTER TABLE empresas DROP INDEX idx_empresas_rfc',
            'ALTER TABLE empresas
                DROP COLUMN color_primario,
                DROP COLUMN logo_path,
                DROP COLUMN codigo_postal,
                DROP COLUMN numero_interior,
                DROP COLUMN numero_exterior,
                DROP COLUMN calle,
                DROP COLUMN colonia,
                DROP COLUMN municipio,
                DROP COLUMN estado,
                DROP COLUMN pais,
                DROP COLUMN sitio_web,
                DROP COLUMN email,
                DROP COLUMN telefono,
                DROP COLUMN regimen_fiscal,
                DROP COLUMN rfc,
                DROP COLUMN nombre_comercial,
                DROP COLUMN razon_social',
        ];
    }
};

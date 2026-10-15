<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_variantes_1_001_create_product_variant_tables';
    }

    public function up(PDO $pdo): void
    {
        $tables = [
            'producto_variante_valores',
            'producto_familia_miembros',
            'producto_familia_atributos',
            'valores_atributo_producto',
            'atributos_producto',
            'producto_familias',
        ];
        try {
            $pdo->exec(<<<'SQL'
            CREATE TABLE producto_familias (
                familia_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (familia_id),
                UNIQUE KEY uq_producto_familias_codigo (codigo),
                KEY idx_producto_familias_activo (activo, eliminado_en),
                CONSTRAINT chk_producto_familias_codigo CHECK (REGEXP_LIKE(codigo, '^[A-Z0-9._/-]{1,40}$', 'c')),
                CONSTRAINT chk_producto_familias_nombre CHECK (CHAR_LENGTH(nombre) BETWEEN 1 AND 120),
                CONSTRAINT chk_producto_familias_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_producto_familias_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_familias_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_familias_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE atributos_producto (
                atributo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                tipo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SELECT',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (atributo_id),
                UNIQUE KEY uq_atributos_producto_codigo (codigo),
                KEY idx_atributos_producto_activo (activo, eliminado_en),
                CONSTRAINT chk_atributos_producto_codigo CHECK (REGEXP_LIKE(codigo, '^[A-Z0-9._/-]{1,40}$', 'c')),
                CONSTRAINT chk_atributos_producto_tipo CHECK (tipo = 'SELECT'),
                CONSTRAINT chk_atributos_producto_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_atributos_producto_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_atributos_producto_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_atributos_producto_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE valores_atributo_producto (
                valor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                atributo_id BIGINT UNSIGNED NOT NULL,
                codigo VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (valor_id),
                UNIQUE KEY uq_valores_atributo_producto_codigo (atributo_id, codigo),
                UNIQUE KEY uq_valores_atributo_producto_pair (atributo_id, valor_id),
                KEY idx_valores_atributo_producto_activo (activo, eliminado_en),
                CONSTRAINT chk_valores_atributo_producto_codigo CHECK (REGEXP_LIKE(codigo, '^[A-Z0-9._/-]{1,40}$', 'c')),
                CONSTRAINT chk_valores_atributo_producto_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_valores_atributo_producto_atributo FOREIGN KEY (atributo_id) REFERENCES atributos_producto (atributo_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_valores_atributo_producto_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_valores_atributo_producto_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_valores_atributo_producto_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE producto_familia_atributos (
                familia_id BIGINT UNSIGNED NOT NULL,
                atributo_id BIGINT UNSIGNED NOT NULL,
                obligatorio TINYINT(1) NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (familia_id, atributo_id),
                CONSTRAINT chk_producto_familia_atributos_obligatorio CHECK (obligatorio IN (0, 1)),
                CONSTRAINT fk_producto_familia_atributos_familia FOREIGN KEY (familia_id) REFERENCES producto_familias (familia_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_familia_atributos_atributo FOREIGN KEY (atributo_id) REFERENCES atributos_producto (atributo_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_familia_atributos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_familia_atributos_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE producto_familia_miembros (
                membresia_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                familia_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
                combination_hash BINARY(32) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                active_membership_marker TINYINT GENERATED ALWAYS AS (CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END) STORED,
                default_active_marker TINYINT GENERATED ALWAYS AS (CASE WHEN activo = 1 AND eliminado_en IS NULL AND es_predeterminada = 1 THEN 1 ELSE NULL END) STORED,
                active_combination_marker TINYINT GENERATED ALWAYS AS (CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END) STORED,
                PRIMARY KEY (membresia_id),
                UNIQUE KEY uq_producto_familia_miembros_active_product (id_producto, active_membership_marker),
                UNIQUE KEY uq_producto_familia_miembros_default (familia_id, default_active_marker),
                UNIQUE KEY uq_producto_familia_miembros_combination (familia_id, combination_hash, active_combination_marker),
                KEY idx_producto_familia_miembros_familia (familia_id, activo, eliminado_en),
                KEY idx_producto_familia_miembros_producto (id_producto),
                CONSTRAINT chk_producto_familia_miembros_predeterminada CHECK (es_predeterminada IN (0, 1)),
                CONSTRAINT chk_producto_familia_miembros_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_producto_familia_miembros_familia FOREIGN KEY (familia_id) REFERENCES producto_familias (familia_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_familia_miembros_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_familia_miembros_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_familia_miembros_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_familia_miembros_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE producto_variante_valores (
                id_producto VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                atributo_id BIGINT UNSIGNED NOT NULL,
                valor_atributo_id BIGINT UNSIGNED NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id_producto, atributo_id),
                KEY idx_producto_variante_valores_valor (valor_atributo_id),
                CONSTRAINT fk_producto_variante_valores_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_variante_valores_atributo FOREIGN KEY (atributo_id) REFERENCES atributos_producto (atributo_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_variante_valores_value_attribute FOREIGN KEY (atributo_id, valor_atributo_id) REFERENCES valores_atributo_producto (atributo_id, valor_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_variante_valores_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_variante_valores_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        } catch (Throwable $exception) {
            foreach ($tables as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
            throw $exception;
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (['producto_variante_valores', 'producto_familia_miembros', 'producto_familia_atributos', 'valores_atributo_producto', 'atributos_producto', 'producto_familias'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
};

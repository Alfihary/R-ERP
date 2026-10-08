<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string { return 'cotizaciones_legacy_1_001_create_mvp_tables'; }
    public function up(PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cotizaciones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                solicitud_cotizacion_id BIGINT UNSIGNED NOT NULL,
                vendedor_usuario_id BIGINT UNSIGNED NOT NULL,
                estado VARCHAR(32) NOT NULL DEFAULT 'BORRADOR',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cotizaciones_solicitud (solicitud_cotizacion_id),
                KEY ix_cotizaciones_vendedor_estado (vendedor_usuario_id, estado),
                CONSTRAINT fk_cotizaciones_solicitud FOREIGN KEY (solicitud_cotizacion_id) REFERENCES solicitudes_cotizacion(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cotizaciones_vendedor FOREIGN KEY (vendedor_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
                CONSTRAINT chk_cotizaciones_estado CHECK (estado IN ('BORRADOR','PENDIENTE_AUTORIZACION','LISTA','ENVIADA','CANCELADA'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cotizacion_partidas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cotizacion_id BIGINT UNSIGNED NOT NULL,
                producto_id BIGINT UNSIGNED NOT NULL,
                precio_publico_origen_id BIGINT UNSIGNED NOT NULL,
                precio_minimo_origen_id BIGINT UNSIGNED NOT NULL,
                lista_precio_publico_id BIGINT UNSIGNED NOT NULL,
                lista_precio_minimo_id BIGINT UNSIGNED NOT NULL,
                moneda_id BIGINT UNSIGNED NOT NULL,
                cantidad DECIMAL(18,6) NOT NULL DEFAULT 1,
                descripcion_snapshot VARCHAR(255) NOT NULL,
                precio_base DECIMAL(18,6) NOT NULL,
                precio_minimo DECIMAL(18,6) NOT NULL,
                precio_venta DECIMAL(18,6) NULL,
                estado_precio VARCHAR(32) NOT NULL DEFAULT 'PENDIENTE',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cotizacion_partida (cotizacion_id),
                KEY ix_cotizacion_partida_producto (producto_id),
                CONSTRAINT fk_cotizacion_partida_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
                CONSTRAINT fk_cotizacion_partida_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cotizacion_partida_publico FOREIGN KEY (precio_publico_origen_id) REFERENCES precios_productos(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cotizacion_partida_minimo FOREIGN KEY (precio_minimo_origen_id) REFERENCES precios_productos(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cotizacion_partida_moneda FOREIGN KEY (moneda_id) REFERENCES monedas(id) ON DELETE RESTRICT,
                CONSTRAINT chk_cotizacion_partida_estado CHECK (estado_precio IN ('PENDIENTE','PERMITIDO','REQUIERE_AUTORIZACION','BLOQUEADO','APROBADO','RECHAZADO')),
                CONSTRAINT chk_cotizacion_partida_precios CHECK (precio_minimo <= precio_base AND (precio_venta IS NULL OR precio_venta >= 0))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS autorizaciones_precio_cotizacion (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cotizacion_id BIGINT UNSIGNED NOT NULL,
                cotizacion_partida_id BIGINT UNSIGNED NOT NULL,
                producto_id BIGINT UNSIGNED NOT NULL,
                solicitado_por BIGINT UNSIGNED NOT NULL,
                decidido_por BIGINT UNSIGNED NULL,
                precio_base_referencia DECIMAL(18,6) NOT NULL,
                precio_minimo_referencia DECIMAL(18,6) NOT NULL,
                precio_solicitado DECIMAL(18,6) NOT NULL,
                cantidad DECIMAL(18,6) NOT NULL,
                moneda_id BIGINT UNSIGNED NOT NULL,
                motivo VARCHAR(1000) NOT NULL,
                estado VARCHAR(16) NOT NULL DEFAULT 'PENDIENTE',
                solicitado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decidido_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY ix_aut_precio_estado (estado, solicitado_at),
                UNIQUE KEY uq_aut_precio_exacta (cotizacion_partida_id, precio_solicitado, estado),
                CONSTRAINT fk_aut_precio_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
                CONSTRAINT fk_aut_precio_partida FOREIGN KEY (cotizacion_partida_id) REFERENCES cotizacion_partidas(id) ON DELETE CASCADE,
                CONSTRAINT fk_aut_precio_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
                CONSTRAINT fk_aut_precio_solicitante FOREIGN KEY (solicitado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
                CONSTRAINT fk_aut_precio_decisor FOREIGN KEY (decidido_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
                CONSTRAINT fk_aut_precio_moneda FOREIGN KEY (moneda_id) REFERENCES monedas(id) ON DELETE RESTRICT,
                CONSTRAINT chk_aut_precio_estado CHECK (estado IN ('PENDIENTE','APROBADA','RECHAZADA','CANCELADA','UTILIZADA')),
                CONSTRAINT chk_aut_precio_rango CHECK (precio_solicitado >= precio_minimo_referencia AND precio_solicitado < precio_base_referencia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            foreach ([
                'precios.autorizaciones.ver' => 'Ver autorizaciones de precio',
                'precios.autorizaciones.solicitar' => 'Solicitar autorización de precio',
                'precios.autorizaciones.aprobar' => 'Aprobar autorización de precio',
                'precios.autorizaciones.rechazar' => 'Rechazar autorización de precio',
            ] as $code => $description) {
                $q=$pdo->prepare('INSERT INTO permisos (codigo,modulo,descripcion,activo) VALUES (:c,:m,:d,1) ON DUPLICATE KEY UPDATE activo=1');
                $q->execute(['c'=>$code,'m'=>'precios','d'=>$description]);
                $pid=(int)$pdo->lastInsertId();
                if($pid===0){$f=$pdo->prepare('SELECT id FROM permisos WHERE codigo=:c');$f->execute(['c'=>$code]);$pid=(int)$f->fetchColumn();}
                $a=$pdo->prepare('INSERT IGNORE INTO rol_permiso (rol_id,permiso_id) SELECT id,:p FROM roles WHERE codigo=\'ADMIN\' AND activo=1');$a->execute(['p'=>$pid]);
            }
            $pdo->inTransaction() && $pdo->commit();
        } catch (Throwable $e) { $pdo->inTransaction() && $pdo->rollBack(); throw $e; }
    }
    public function down(PDO $pdo): void { $pdo->exec('DROP TABLE IF EXISTS autorizaciones_precio_cotizacion'); $pdo->exec('DROP TABLE IF EXISTS cotizacion_partidas'); $pdo->exec('DROP TABLE IF EXISTS cotizaciones'); }
};



<?php

declare(strict_types=1);

return [
    'version' => 1,
    'fields' => [
        'id_producto' => ['type' => 'string', 'label' => 'ID de producto'],
        'descripcion' => ['type' => 'string', 'label' => 'Descripción'],
        'descripcion_larga' => ['type' => 'string|null', 'label' => 'Descripción larga'],
        'tipo_producto_id' => ['type' => 'int', 'label' => 'Tipo de producto'],
        'unidad_medida_id' => ['type' => 'int', 'label' => 'Unidad de medida'],
        'moneda_id' => ['type' => 'int|null', 'label' => 'Moneda'],
        'linea_producto_id' => ['type' => 'int|null', 'label' => 'Línea'],
        'marca_id' => ['type' => 'int|null', 'label' => 'Marca'],
        'clasificacion_producto_id' => ['type' => 'int|null', 'label' => 'Clasificación'],
        'activo' => ['type' => 'bool', 'label' => 'Estado'],
    ],
    'events' => [
        'productos.crear' => ['label' => 'Producto creado', 'subresource' => null],
        'productos.actualizar' => ['label' => 'Producto actualizado', 'subresource' => null],
        'productos.activar' => ['label' => 'Producto activado', 'subresource' => null],
        'productos.desactivar' => ['label' => 'Producto desactivado', 'subresource' => null],
        'producto_imagen.subir' => [
            'label' => 'Imagen agregada', 'subresource' => 'imagen', 'entity' => 'productos', 'product_id_strategy' => 'metadata.producto_id',
            'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1',
        ],
        'producto_imagen.reemplazar' => [
            'label' => 'Imagen reemplazada', 'subresource' => 'imagen', 'entity' => 'productos', 'product_id_strategy' => 'metadata.producto_id',
            'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1',
        ],
        'producto_imagen.eliminar' => [
            'label' => 'Imagen eliminada', 'subresource' => 'imagen', 'entity' => 'productos', 'product_id_strategy' => 'metadata.producto_id',
            'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1',
        ],
        'productos.similares.agregar' => [
            'label' => 'Similar agregado', 'subresource' => 'similar', 'entity' => 'producto_similar', 'product_id_strategy' => 'metadata.producto_id',
            'product_id_source' => 'metadata.producto_id', 'related_product_id_source' => 'metadata.producto_relacionado_id', 'contract' => 'CANONICAL_V1',
        ],
        'productos.similares.quitar' => [
            'label' => 'Similar quitado', 'subresource' => 'similar', 'entity' => 'producto_similar', 'product_id_strategy' => 'metadata.producto_id',
            'product_id_source' => 'metadata.producto_id', 'related_product_id_source' => 'metadata.producto_relacionado_id', 'contract' => 'CANONICAL_V1',
        ],
        'productos.kits.componentes.agregar' => ['label' => 'Componente agregado', 'subresource' => 'kit_componente', 'entity' => 'producto_kit_componente', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.kits.componentes.actualizar' => ['label' => 'Componente actualizado', 'subresource' => 'kit_componente', 'entity' => 'producto_kit_componente', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.kits.componentes.quitar' => ['label' => 'Componente quitado', 'subresource' => 'kit_componente', 'entity' => 'producto_kit_componente', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.kits.componentes.reactivar' => ['label' => 'Componente reactivado', 'subresource' => 'kit_componente', 'entity' => 'producto_kit_componente', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.variantes.vincular' => ['label' => 'Variante vinculada', 'subresource' => 'variante', 'entity' => 'producto_familia_miembro', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.variantes.desvincular' => ['label' => 'Variante desvinculada', 'subresource' => 'variante', 'entity' => 'producto_familia_miembro', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.variantes.atributos.actualizar' => ['label' => 'Atributos de variante actualizados', 'subresource' => 'variante', 'entity' => 'producto_familia_miembro', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.variantes.predeterminada' => ['label' => 'Variante predeterminada actualizada', 'subresource' => 'variante', 'entity' => 'producto_familia_miembro', 'product_id_strategy' => 'metadata.producto_id', 'product_id_source' => 'metadata.producto_id', 'contract' => 'CANONICAL_V1'],
        'productos.importacion.lote' => ['label' => 'Importación por lote', 'subresource' => 'importacion', 'entity' => 'producto_importacion', 'product_id_strategy' => 'none_batch', 'product_id_source' => null, 'contract' => 'CANONICAL_V1_BATCH'],
    ],
];

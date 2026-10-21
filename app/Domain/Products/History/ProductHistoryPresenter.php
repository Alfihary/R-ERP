<?php

declare(strict_types=1);

namespace App\Domain\Products\History;

/**
 * Builds safe, presentation-ready values without rendering HTML.
 *
 * @param array<string, array<string, mixed>> $registry
 */
final class ProductHistoryPresenter
{
    private const CATEGORIES = [
        'imagen' => 'image',
        'similar' => 'similar',
        'kit_componente' => 'kit',
        'variante' => 'variant',
    ];

    private const SUMMARIES = [
        'productos.crear' => 'Producto creado',
        'productos.actualizar' => 'Producto actualizado',
        'productos.activar' => 'Producto activado',
        'productos.desactivar' => 'Producto desactivado',
        'producto_imagen.subir' => 'Imagen agregada',
        'producto_imagen.reemplazar' => 'Imagen reemplazada',
        'producto_imagen.eliminar' => 'Imagen eliminada',
        'productos.similares.agregar' => 'Producto similar agregado',
        'productos.similares.quitar' => 'Producto similar retirado',
        'productos.kits.componentes.agregar' => 'Componente agregado al kit',
        'productos.kits.componentes.actualizar' => 'Cantidad del componente actualizada',
        'productos.kits.componentes.quitar' => 'Componente retirado del kit',
        'productos.kits.componentes.reactivar' => 'Componente reactivado en el kit',
        'productos.variantes.vincular' => 'Variante vinculada',
        'productos.variantes.desvincular' => 'Variante desvinculada',
        'productos.variantes.atributos.actualizar' => 'Atributos de variante actualizados',
        'productos.variantes.predeterminada' => 'Variante predeterminada actualizada',
    ];

    /**
     * @param array<string, array<string, mixed>> $registry
     */
    public function __construct(private readonly array $registry)
    {
    }

    /**
     * @return array{category:string, subresource:?string, summary:string}
     */
    public function present(string $action, ?string $subresource): array
    {
        $event = $this->registry[$action] ?? [];
        $registeredSubresource = $event['subresource'] ?? $subresource;
        $category = is_string($registeredSubresource)
            ? (self::CATEGORIES[$registeredSubresource] ?? 'product')
            : 'product';

        $summary = self::SUMMARIES[$action] ?? null;
        if ($summary === null && is_string($event['label'] ?? null)) {
            $summary = $event['label'];
        }

        return [
            'category' => $category,
            'subresource' => is_string($registeredSubresource)
                ? $registeredSubresource
                : null,
            'summary' => $summary ?? 'Evento de producto',
        ];
    }
}

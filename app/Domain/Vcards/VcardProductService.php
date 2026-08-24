<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

use App\Infrastructure\Repositories\VcardProductRepository;

final class VcardProductService
{
    public function __construct(
        private readonly VcardProductRepository $products,
        private readonly VcardService $vcards
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarPrivados(int $usuarioId): array
    {
        $vcard = $this->ensureVcard($usuarioId);

        return $this->safePrivateProducts(
            $this->products->privateLinkedProducts((int) $vcard['id'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buscarProductosActivos(string $term): array
    {
        return $this->safePrivateProducts(
            $this->products->searchActiveProducts($term)
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function agregarProducto(int $usuarioId, array $input): void
    {
        $pdo = $this->products->pdo();
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $vcard = $this->ensureVcard($usuarioId);
            $productId = $this->normalizedProductId($input['id_producto'] ?? '');

            if (!$this->products->activeProductExists($productId)) {
                throw new VcardValidationException([
                    'id_producto' => 'El producto debe existir y estar activo.',
                ]);
            }

            $this->products->upsertProduct(
                (int) $vcard['id'],
                $usuarioId,
                $productId,
                1,
                !empty($input['destacado']) ? 1 : 0,
                $this->nullableText($input['texto_publico'] ?? null)
            );

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function actualizarProducto(int $usuarioId, array $input): void
    {
        $vcard = $this->ensureVcard($usuarioId);
        $productId = $this->normalizedProductId($input['id_producto'] ?? '');
        $updated = $this->products->updateProductLink(
            (int) $vcard['id'],
            $productId,
            !empty($input['activo']) ? 1 : 0,
            !empty($input['destacado']) ? 1 : 0,
            $this->nullableText($input['texto_publico'] ?? null)
        );

        if (!$updated) {
            throw new VcardValidationException([
                'id_producto' => 'El producto no está vinculado a tu vCard.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function quitarProducto(int $usuarioId, array $input): void
    {
        $vcard = $this->ensureVcard($usuarioId);
        $productId = $this->normalizedProductId($input['id_producto'] ?? '');
        $removed = $this->products->removeProductLink((int) $vcard['id'], $productId);

        if (!$removed) {
            throw new VcardValidationException([
                'id_producto' => 'El producto no está vinculado a tu vCard.',
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $productos
     */
    public function sincronizarProductos(int $usuarioId, array $productos): void
    {
        $pdo = $this->products->pdo();
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $vcard = $this->ensureVcard($usuarioId);
            $normalized = $this->normalizedProducts($productos);
            $this->products->syncProducts((int) $vcard['id'], $usuarioId, $normalized);

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarPublicosPorSlug(string $slug): array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return [];
        }

        return $this->safePublicProducts($this->products->publicProductsBySlug($slug));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function imagenPublicaPorSlug(string $slug, string $productId): ?array
    {
        $slug = trim($slug);
        $productId = $this->normalizedProductId($productId);

        if ($slug === '') {
            return null;
        }

        $photo = $this->products->publicProductMainPhotoBySlug($slug, $productId);

        if ($photo === null) {
            return null;
        }

        return [
            'id_producto' => (string) ($photo['id_producto'] ?? ''),
            'ruta_relativa' => (string) ($photo['ruta_relativa'] ?? ''),
            'mime_type' => (string) ($photo['mime_type'] ?? ''),
            'tamano_bytes' => (int) ($photo['tamano_bytes'] ?? 0),
            'creado_en' => $photo['creado_en'] ?? null,
            'actualizado_en' => $photo['actualizado_en'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureVcard(int $usuarioId): array
    {
        if ($this->products->activeUser($usuarioId) === null) {
            throw new VcardValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }

        return $this->vcards->asegurarVcard($usuarioId);
    }

    /**
     * @param list<array<string, mixed>> $productos
     * @return list<array{
     *     id_producto: string,
     *     activo: int,
     *     destacado: int,
     *     orden: int,
     *     texto_publico: string|null
     * }>
     */
    private function normalizedProducts(array $productos): array
    {
        $normalized = [];
        $seen = [];
        $position = 0;

        foreach ($productos as $product) {
            $id = strtoupper(trim((string) ($product['id_producto'] ?? '')));

            if ($id === '') {
                continue;
            }

            if (!preg_match('/^[A-Z0-9]{1,16}$/', $id)) {
                throw new VcardValidationException([
                    'id_producto' => 'El producto no es válido.',
                ]);
            }

            if (!$this->products->productExists($id)) {
                throw new VcardValidationException([
                    'id_producto' => 'El producto no existe.',
                ]);
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $order = (int) ($product['orden'] ?? $position);
            $text = $this->nullableText($product['texto_publico'] ?? null);

            $normalized[] = [
                'id_producto' => $id,
                'activo' => !empty($product['activo']) ? 1 : 0,
                'destacado' => !empty($product['destacado']) ? 1 : 0,
                'orden' => max(0, $order),
                'texto_publico' => $text,
            ];
            $position++;
        }

        return $normalized;
    }

    private function nullableText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 255) {
            throw new VcardValidationException([
                'texto_publico' => 'Máximo 255 caracteres.',
            ]);
        }

        if (!function_exists('mb_strlen') && strlen($text) > 255) {
            throw new VcardValidationException([
                'texto_publico' => 'Máximo 255 caracteres.',
            ]);
        }

        return $text;
    }

    private function normalizedProductId(mixed $value): string
    {
        $id = strtoupper(trim(is_scalar($value) ? (string) $value : ''));

        if ($id === '' || !preg_match('/^[A-Z0-9]{1,16}$/', $id)) {
            throw new VcardValidationException([
                'id_producto' => 'El producto no es válido.',
            ]);
        }

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function safePrivateProducts(array $rows): array
    {
        return array_map($this->safeProduct(...), $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function safePublicProducts(array $rows): array
    {
        return array_map($this->safeProduct(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function safeProduct(array $row): array
    {
        return [
            'id_producto' => (string) ($row['id_producto'] ?? ''),
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'texto_publico' => ($row['texto_publico'] ?? null) !== null
                ? (string) $row['texto_publico']
                : null,
            'destacado' => (int) ($row['destacado'] ?? 0) === 1,
            'orden' => (int) ($row['orden'] ?? 0),
            'unidad' => (string) ($row['unidad_nombre'] ?? $row['unidad_codigo'] ?? ''),
            'marca' => ($row['marca_nombre'] ?? null) !== null
                ? (string) $row['marca_nombre']
                : null,
            'linea' => ($row['linea_nombre'] ?? null) !== null
                ? (string) $row['linea_nombre']
                : null,
            'clasificacion' => ($row['clasificacion_nombre'] ?? null) !== null
                ? (string) $row['clasificacion_nombre']
                : null,
            'imagen_disponible' => !empty($row['imagen_id']),
            'activo' => array_key_exists('activo', $row)
                ? (int) $row['activo'] === 1
                : true,
        ];
    }
}

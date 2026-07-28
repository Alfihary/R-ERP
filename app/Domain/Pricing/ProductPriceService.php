<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use PDOException;
use Throwable;

final class ProductPriceService
{
    private const MONEY_SCALE = 4;
    private const MAX_INTEGER_DIGITS = 10;

    public function __construct(
        private readonly ProductPriceRepository $prices,
        private readonly PriceListRepository $lists,
        private readonly ProductPriceHistoryRepository $history
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function crearPrecio(array $input): array
    {
        $request = $this->validateCreateInput($input);

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['usuario_id']);
            $product = $this->assertActiveProductWithCurrency(
                $request['id_producto']
            );
            $list = $this->assertActiveList($request['lista_precio_id']);

            if (
                $this->prices->findByProductAndListForUpdate(
                    $request['id_producto'],
                    $request['lista_precio_id']
                ) !== null
            ) {
                throw new PricingValidationException([
                    'lista_precio_id' =>
                        'El producto ya tiene precio para esta lista.',
                ]);
            }

            $priceId = $this->prices->insert([
                'id_producto' => $request['id_producto'],
                'lista_precio_id' => $request['lista_precio_id'],
                'moneda_id' => (int) $product['moneda_id'],
                'precio_lista' => $request['precio_lista'],
                'precio_minimo' => $request['precio_minimo'],
                'incluye_impuestos' => (int) $list['incluye_impuestos'],
                'requiere_revision' => 0,
                'activo' => 1,
                'creado_por' => $request['usuario_id'],
                'actualizado_por' => $request['usuario_id'],
            ]);

            $created = $this->prices->findById($priceId);

            if ($created === null) {
                throw new \RuntimeException('Created product price not found.');
            }

            $this->insertHistory(
                $created,
                null,
                'CREACION',
                $request['motivo_cambio'],
                $request['usuario_id']
            );

            return $this->normalizePrice($created);
        });
    }

    /**
     * @param list<array<string, mixed>> $precios
     * @return list<array<string, mixed>>
     */
    public function crearPreciosInicialesProducto(
        string $idProducto,
        array $precios,
        int $usuarioId
    ): array {
        if ($precios === []) {
            return [];
        }

        $idProducto = $this->productId($idProducto);
        $this->assertNoDuplicateLists($precios);

        return $this->transactional(function () use (
            $idProducto,
            $precios,
            $usuarioId
        ): array {
            $created = [];

            foreach (array_values($precios) as $price) {
                if (!is_array($price)) {
                    throw new PricingValidationException([
                        'precios' => 'Cada precio inicial debe ser un arreglo.',
                    ]);
                }

                $created[] = $this->crearPrecio([
                    'id_producto' => $idProducto,
                    'lista_precio_id' => $price['lista_precio_id'] ?? null,
                    'precio_lista' => $price['precio_lista'] ?? null,
                    'precio_minimo' => $price['precio_minimo'] ?? null,
                    'usuario_id' => $usuarioId,
                    'motivo_cambio' => $price['motivo_cambio']
                        ?? 'Precio inicial del producto.',
                ]);
            }

            return $created;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function actualizarPrecio(array $input): array
    {
        $request = $this->validateUpdateInput($input);

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['usuario_id']);
            $current = $this->assertCurrentPriceForUpdate(
                $request['producto_precio_id']
            );
            $product = $this->assertActiveProductWithCurrency(
                (string) $current['id_producto']
            );
            $list = $this->assertActiveList((int) $current['lista_precio_id']);

            $updatedData = [
                'moneda_id' => (int) $product['moneda_id'],
                'precio_lista' => $request['precio_lista'],
                'precio_minimo' => $request['precio_minimo'],
                'incluye_impuestos' => (int) $list['incluye_impuestos'],
                'requiere_revision' => 0,
                'activo' => (int) $current['activo'],
                'actualizado_por' => $request['usuario_id'],
            ];

            $this->prices->update((int) $current['id'], $updatedData);
            $updated = $this->prices->findById((int) $current['id']);

            if ($updated === null) {
                throw new \RuntimeException('Updated product price not found.');
            }

            $this->insertHistory(
                $updated,
                $current,
                'ACTUALIZACION',
                $request['motivo_cambio'],
                $request['usuario_id']
            );

            return $this->normalizePrice($updated);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function desactivarPrecio(
        int $productoPrecioId,
        int $usuarioId,
        string $motivo
    ): array {
        $motivo = $this->requiredReason($motivo);

        return $this->transactional(function () use (
            $productoPrecioId,
            $usuarioId,
            $motivo
        ): array {
            $this->assertUser($usuarioId);
            $current = $this->assertCurrentPriceForUpdate($productoPrecioId);

            if ((int) $current['activo'] === 0) {
                return $this->normalizePrice($current);
            }

            $updatedData = [
                'moneda_id' => (int) $current['moneda_id'],
                'precio_lista' => (string) $current['precio_lista'],
                'precio_minimo' => (string) $current['precio_minimo'],
                'incluye_impuestos' => (int) $current['incluye_impuestos'],
                'requiere_revision' => (int) $current['requiere_revision'],
                'activo' => 0,
                'actualizado_por' => $usuarioId,
            ];

            $this->prices->update((int) $current['id'], $updatedData);
            $updated = $this->prices->findById((int) $current['id']);

            if ($updated === null) {
                throw new \RuntimeException('Deactivated product price not found.');
            }

            $this->insertHistory(
                $updated,
                $current,
                'DESACTIVACION',
                $motivo,
                $usuarioId
            );

            return $this->normalizePrice($updated);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivarPrecio(
        int $productoPrecioId,
        int $usuarioId,
        string $motivo
    ): array {
        $motivo = $this->requiredReason($motivo);

        return $this->transactional(function () use (
            $productoPrecioId,
            $usuarioId,
            $motivo
        ): array {
            $this->assertUser($usuarioId);
            $current = $this->assertCurrentPriceForUpdate($productoPrecioId);

            if ((int) $current['requiere_revision'] === 1) {
                throw new PricingValidationException([
                    'producto_precio_id' =>
                        'Actualiza el precio antes de reactivarlo.',
                ]);
            }

            if ($this->compareMoney((string) $current['precio_lista'], '0.0000') <= 0) {
                throw new PricingValidationException([
                    'precio_lista' =>
                        'El precio de lista debe ser mayor que cero.',
                ]);
            }

            if ((int) $current['activo'] === 1) {
                return $this->normalizePrice($current);
            }

            $this->prices->reactivate((int) $current['id'], $usuarioId);
            $updated = $this->prices->findById((int) $current['id']);

            if ($updated === null) {
                throw new \RuntimeException('Reactivated product price not found.');
            }

            $this->insertHistory(
                $updated,
                $current,
                'REACTIVACION',
                $motivo,
                $usuarioId
            );

            return $this->normalizePrice($updated);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function cambiarMonedaProductoConPrecios(array $input): array
    {
        $request = $this->validateCurrencyChangeInput($input);

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['usuario_id']);

            if (!$this->prices->activeCurrencyExists($request['moneda_id_nueva'])) {
                throw new PricingValidationException([
                    'moneda_id_nueva' => 'La moneda no existe o no está activa.',
                ]);
            }

            $product = $this->prices->productForUpdate($request['id_producto']);

            if (
                $product === null
                || (int) $product['activo'] !== 1
                || $product['eliminado_en'] !== null
            ) {
                throw new PricingValidationException([
                    'id_producto' => 'El producto no existe o no está activo.',
                ]);
            }

            $previousCurrencyId = $product['moneda_id'] === null
                ? null
                : (int) $product['moneda_id'];

            if ($previousCurrencyId === $request['moneda_id_nueva']) {
                return [
                    'changed' => false,
                    'id_producto' => $request['id_producto'],
                    'moneda_id' => $previousCurrencyId,
                    'precios_actualizados' => [],
                    'precios_en_revision' => [],
                ];
            }

            $prices = $this->prices->findAllByProductForUpdate(
                $request['id_producto']
            );
            $updatesByList = $this->currencyUpdatesByList(
                $request['precios_actualizados']
            );

            $knownListIds = array_map(
                static fn (array $price): int => (int) $price['lista_precio_id'],
                $prices
            );

            foreach (array_keys($updatesByList) as $listId) {
                if (!in_array($listId, $knownListIds, true)) {
                    throw new PricingValidationException([
                        'precios_actualizados' =>
                            'No se crean precios nuevos al cambiar moneda.',
                    ]);
                }
            }

            $this->prices->updateProductCurrency(
                $request['id_producto'],
                $request['moneda_id_nueva'],
                $request['usuario_id']
            );

            $updatedIds = [];
            $revisionIds = [];

            foreach ($prices as $current) {
                $listId = (int) $current['lista_precio_id'];
                $list = $this->assertActiveList($listId);
                $captured = $updatesByList[$listId] ?? null;
                $newListPrice = $captured['precio_lista'] ?? '0.0000';
                $newMinimumPrice = $captured['precio_minimo'] ?? '0.0000';
                $requiresReview = $captured === null ? 1 : 0;

                $this->prices->update((int) $current['id'], [
                    'moneda_id' => $request['moneda_id_nueva'],
                    'precio_lista' => $newListPrice,
                    'precio_minimo' => $newMinimumPrice,
                    'incluye_impuestos' => (int) $list['incluye_impuestos'],
                    'requiere_revision' => $requiresReview,
                    'activo' => (int) $current['activo'],
                    'actualizado_por' => $request['usuario_id'],
                ]);

                $updated = $this->prices->findById((int) $current['id']);

                if ($updated === null) {
                    throw new \RuntimeException('Currency-updated price not found.');
                }

                $this->insertHistory(
                    $updated,
                    $current,
                    'CAMBIO_MONEDA',
                    $request['motivo_cambio'],
                    $request['usuario_id']
                );

                if ($requiresReview === 1) {
                    $revisionIds[] = (int) $updated['id'];
                } else {
                    $updatedIds[] = (int) $updated['id'];
                }
            }

            return [
                'changed' => true,
                'id_producto' => $request['id_producto'],
                'moneda_id_anterior' => $previousCurrencyId,
                'moneda_id_nueva' => $request['moneda_id_nueva'],
                'precios_actualizados' => $updatedIds,
                'precios_en_revision' => $revisionIds,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerPrecioUtilizable(
        string $idProducto,
        int $listaPrecioId
    ): ?array {
        $idProducto = $this->productId($idProducto);
        $listId = $this->positiveIdValue($listaPrecioId, 'lista_precio_id');
        $price = $this->prices->usablePrice($idProducto, $listId);

        return $price === null ? null : $this->normalizePrice($price);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function evaluarPrecioSolicitado(array $input): array
    {
        $idProducto = $this->productId((string) ($input['id_producto'] ?? ''));
        $listId = $this->positiveId($input['lista_precio_id'] ?? null, 'lista_precio_id');
        $unitPrice = $this->money($input['precio_unitario'] ?? null, 'precio_unitario');
        $price = $this->prices->findByProductAndList($idProducto, $listId);

        if ($price === null) {
            return [
                'resultado' => 'SIN_PRECIO',
                'id_producto' => $idProducto,
                'lista_precio_id' => $listId,
                'precio_unitario' => $unitPrice,
            ];
        }

        $base = [
            'producto_precio_id' => (int) $price['id'],
            'id_producto' => (string) $price['id_producto'],
            'lista_precio_id' => (int) $price['lista_precio_id'],
            'moneda_id' => (int) $price['moneda_id'],
            'precio_lista' => (string) $price['precio_lista'],
            'precio_minimo' => (string) $price['precio_minimo'],
            'precio_unitario' => $unitPrice,
            'incluye_impuestos' => (int) $price['incluye_impuestos'],
        ];

        if ((int) $price['requiere_revision'] === 1) {
            return $base + ['resultado' => 'PRECIO_EN_REVISION'];
        }

        if (
            (int) $price['activo'] !== 1
            || $price['eliminado_en'] !== null
            || $this->compareMoney((string) $price['precio_lista'], '0.0000') <= 0
        ) {
            return $base + ['resultado' => 'SIN_PRECIO_UTILIZABLE'];
        }

        if ($this->compareMoney($unitPrice, (string) $price['precio_lista']) >= 0) {
            return $base + ['resultado' => 'PERMITIDO'];
        }

        if ($this->compareMoney($unitPrice, (string) $price['precio_minimo']) >= 0) {
            return $base + ['resultado' => 'REQUIERE_AUTORIZACION'];
        }

        return $base + ['resultado' => 'BLOQUEADO'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarListasActivas(): array
    {
        return $this->lists->listActive();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarHistorialProductoPrecio(int $productoPrecioId): array
    {
        return $this->history->listByProductPrice(
            $this->positiveIdValue($productoPrecioId, 'producto_precio_id')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarPreciosProducto(string $idProducto): array
    {
        return array_map(
            fn (array $price): array => $this->normalizePrice($price),
            $this->prices->listProductPricesWithDetails(
                $this->productId($idProducto)
            )
        );
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = $this->prices->beginTransaction();

        try {
            $result = $operation();
            $this->prices->commit($ownsTransaction);

            return $result;
        } catch (PricingValidationException $exception) {
            $this->prices->rollBack($ownsTransaction);
            throw $exception;
        } catch (PDOException $exception) {
            $this->prices->rollBack($ownsTransaction);
            $this->convertDatabaseError($exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->prices->rollBack($ownsTransaction);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id_producto: string, lista_precio_id: int, precio_lista: string, precio_minimo: string, usuario_id: int, motivo_cambio: string}
     */
    private function validateCreateInput(array $input): array
    {
        $request = [
            'id_producto' => $this->productId((string) ($input['id_producto'] ?? '')),
            'lista_precio_id' => $this->positiveId(
                $input['lista_precio_id'] ?? null,
                'lista_precio_id'
            ),
            'precio_lista' => $this->money(
                $input['precio_lista'] ?? null,
                'precio_lista'
            ),
            'precio_minimo' => $this->money(
                $input['precio_minimo'] ?? null,
                'precio_minimo'
            ),
            'usuario_id' => $this->positiveId(
                $input['usuario_id'] ?? null,
                'usuario_id'
            ),
            'motivo_cambio' => $this->requiredReason(
                (string) ($input['motivo_cambio'] ?? '')
            ),
        ];

        $this->assertMoneyOrder(
            $request['precio_lista'],
            $request['precio_minimo']
        );

        return $request;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{producto_precio_id: int, precio_lista: string, precio_minimo: string, usuario_id: int, motivo_cambio: string}
     */
    private function validateUpdateInput(array $input): array
    {
        $request = [
            'producto_precio_id' => $this->positiveId(
                $input['producto_precio_id'] ?? null,
                'producto_precio_id'
            ),
            'precio_lista' => $this->money(
                $input['precio_lista'] ?? null,
                'precio_lista'
            ),
            'precio_minimo' => $this->money(
                $input['precio_minimo'] ?? null,
                'precio_minimo'
            ),
            'usuario_id' => $this->positiveId(
                $input['usuario_id'] ?? null,
                'usuario_id'
            ),
            'motivo_cambio' => $this->requiredReason(
                (string) ($input['motivo_cambio'] ?? '')
            ),
        ];

        $this->assertMoneyOrder(
            $request['precio_lista'],
            $request['precio_minimo']
        );

        return $request;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id_producto: string, moneda_id_nueva: int, precios_actualizados: list<array{lista_precio_id: int, precio_lista: string, precio_minimo: string}>, usuario_id: int, motivo_cambio: string}
     */
    private function validateCurrencyChangeInput(array $input): array
    {
        $updates = $input['precios_actualizados'] ?? [];

        if (!is_array($updates)) {
            throw new PricingValidationException([
                'precios_actualizados' =>
                    'Los precios actualizados deben ser una lista.',
            ]);
        }

        $normalizedUpdates = [];

        foreach (array_values($updates) as $index => $update) {
            if (!is_array($update)) {
                throw new PricingValidationException([
                    'precios_actualizados.' . $index =>
                        'Cada precio actualizado debe ser un arreglo.',
                ]);
            }

            $normalizedUpdates[] = [
                'lista_precio_id' => $this->positiveId(
                    $update['lista_precio_id'] ?? null,
                    'lista_precio_id'
                ),
                'precio_lista' => $this->money(
                    $update['precio_lista'] ?? null,
                    'precio_lista'
                ),
                'precio_minimo' => $this->money(
                    $update['precio_minimo'] ?? null,
                    'precio_minimo'
                ),
            ];
        }

        $this->assertNoDuplicateLists($normalizedUpdates);

        return [
            'id_producto' => $this->productId((string) ($input['id_producto'] ?? '')),
            'moneda_id_nueva' => $this->positiveId(
                $input['moneda_id_nueva'] ?? null,
                'moneda_id_nueva'
            ),
            'precios_actualizados' => $normalizedUpdates,
            'usuario_id' => $this->positiveId(
                $input['usuario_id'] ?? null,
                'usuario_id'
            ),
            'motivo_cambio' => $this->requiredReason(
                (string) ($input['motivo_cambio'] ?? '')
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $precios
     */
    private function assertNoDuplicateLists(array $precios): void
    {
        $seen = [];

        foreach ($precios as $price) {
            if (!is_array($price)) {
                continue;
            }

            $listId = $this->positiveId(
                $price['lista_precio_id'] ?? null,
                'lista_precio_id'
            );

            if (isset($seen[$listId])) {
                throw new PricingValidationException([
                    'lista_precio_id' => 'No repitas listas de precios.',
                ]);
            }

            $seen[$listId] = true;
        }
    }

    /**
     * @param list<array{lista_precio_id: int, precio_lista: string, precio_minimo: string}> $updates
     * @return array<int, array{precio_lista: string, precio_minimo: string}>
     */
    private function currencyUpdatesByList(array $updates): array
    {
        $byList = [];

        foreach ($updates as $update) {
            $this->assertMoneyOrder(
                $update['precio_lista'],
                $update['precio_minimo']
            );
            $byList[$update['lista_precio_id']] = [
                'precio_lista' => $update['precio_lista'],
                'precio_minimo' => $update['precio_minimo'],
            ];
        }

        return $byList;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertActiveProductWithCurrency(string $idProducto): array
    {
        $product = $this->prices->activeProduct($idProducto);

        if ($product === null) {
            throw new PricingValidationException([
                'id_producto' => 'El producto no existe o no está activo.',
            ]);
        }

        if ($product['moneda_id'] === null) {
            throw new PricingValidationException([
                'moneda_id' => 'El producto no tiene moneda base.',
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertActiveList(int $listId): array
    {
        $list = $this->lists->findActiveById($listId);

        if ($list === null) {
            throw new PricingValidationException([
                'lista_precio_id' => 'La lista de precios no existe o no está activa.',
            ]);
        }

        return $list;
    }

    private function assertUser(int $userId): void
    {
        if (!$this->prices->activeUserExists($userId)) {
            throw new PricingValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assertCurrentPriceForUpdate(int $priceId): array
    {
        $price = $this->prices->findByIdForUpdate($priceId);

        if ($price === null || $price['eliminado_en'] !== null) {
            throw new PricingValidationException([
                'producto_precio_id' => 'El precio no existe.',
            ]);
        }

        return $price;
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed>|null $old
     */
    private function insertHistory(
        array $new,
        ?array $old,
        string $changeType,
        string $reason,
        int $actorId
    ): void {
        $this->history->insert([
            'producto_precio_id' => (int) $new['id'],
            'id_producto' => (string) $new['id_producto'],
            'lista_precio_id' => (int) $new['lista_precio_id'],
            'moneda_id_anterior' => $old === null
                ? null
                : (int) $old['moneda_id'],
            'moneda_id_nueva' => (int) $new['moneda_id'],
            'precio_lista_anterior' => $old === null
                ? null
                : (string) $old['precio_lista'],
            'precio_minimo_anterior' => $old === null
                ? null
                : (string) $old['precio_minimo'],
            'incluye_impuestos_anterior' => $old === null
                ? null
                : (int) $old['incluye_impuestos'],
            'requiere_revision_anterior' => $old === null
                ? null
                : (int) $old['requiere_revision'],
            'activo_anterior' => $old === null ? null : (int) $old['activo'],
            'precio_lista_nuevo' => (string) $new['precio_lista'],
            'precio_minimo_nuevo' => (string) $new['precio_minimo'],
            'incluye_impuestos_nuevo' => (int) $new['incluye_impuestos'],
            'requiere_revision_nuevo' => (int) $new['requiere_revision'],
            'activo_nuevo' => (int) $new['activo'],
            'tipo_cambio' => $changeType,
            'motivo_cambio' => $reason,
            'cambiado_por' => $actorId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePrice(array $price): array
    {
        return [
            'id' => (int) $price['id'],
            'id_producto' => (string) $price['id_producto'],
            'lista_precio_id' => (int) $price['lista_precio_id'],
            'moneda_id' => (int) $price['moneda_id'],
            'precio_lista' => (string) $price['precio_lista'],
            'precio_minimo' => (string) $price['precio_minimo'],
            'incluye_impuestos' => (int) $price['incluye_impuestos'],
            'requiere_revision' => (int) $price['requiere_revision'],
            'activo' => (int) $price['activo'],
            ...array_filter([
                'lista_clave' => $price['lista_clave'] ?? null,
                'lista_nombre' => $price['lista_nombre'] ?? null,
                'moneda_codigo' => $price['moneda_codigo'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function productId(string $value): string
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9]{1,16}$/', $value) !== 1) {
            throw new PricingValidationException([
                'id_producto' => 'El producto no es válido.',
            ]);
        }

        return $value;
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            throw new PricingValidationException([
                $field => 'El identificador debe ser entero positivo.',
            ]);
        }

        return (int) $value;
    }

    private function positiveIdValue(int $value, string $field): int
    {
        if ($value < 1) {
            throw new PricingValidationException([
                $field => 'El identificador debe ser entero positivo.',
            ]);
        }

        return $value;
    }

    private function money(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new PricingValidationException([
                $field => 'El importe debe ser decimal no negativo.',
            ]);
        }

        $value = trim((string) $value);
        $pattern = '/^(?:0|[1-9]\d{0,'
            . (self::MAX_INTEGER_DIGITS - 1)
            . '})(?:\.\d{1,'
            . self::MONEY_SCALE
            . '})?$/';

        if (preg_match($pattern, $value) !== 1) {
            throw new PricingValidationException([
                $field => 'El importe debe ser decimal no negativo.',
            ]);
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $integer . '.' . str_pad($fraction, self::MONEY_SCALE, '0');
    }

    private function assertMoneyOrder(string $listPrice, string $minimumPrice): void
    {
        if ($this->compareMoney($minimumPrice, $listPrice) > 0) {
            throw new PricingValidationException([
                'precio_minimo' =>
                    'El precio mínimo no puede exceder el precio de lista.',
            ]);
        }
    }

    private function compareMoney(string $left, string $right): int
    {
        return $this->moneyUnits($left) <=> $this->moneyUnits($right);
    }

    private function moneyUnits(string $value): int
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $integer * 10_000)
            + (int) str_pad(substr($fraction, 0, 4), 4, '0');
    }

    private function requiredReason(string $value): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 500) {
            throw new PricingValidationException([
                'motivo_cambio' =>
                    'El motivo de cambio es obligatorio y admite hasta 500 caracteres.',
            ]);
        }

        return $value;
    }

    private function convertDatabaseError(PDOException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new PricingValidationException([
                'lista_precio_id' =>
                    'El producto ya tiene precio para esta lista.',
            ]);
        }
    }
}

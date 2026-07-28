<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Infrastructure\Repositories\PriceAuthorizationRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use PDOException;
use Throwable;

final class PriceAuthorizationService
{
    private const MONEY_SCALE = 4;
    private const MAX_INTEGER_DIGITS = 10;
    private const VALID_STATUSES = [
        'PENDIENTE',
        'APROBADA',
        'RECHAZADA',
        'CANCELADA',
        'VENCIDA',
        'UTILIZADA',
    ];

    public function __construct(
        private readonly PriceAuthorizationRepository $authorizations,
        private readonly ProductPriceService $productPrices,
        private readonly ProductPriceRepository $prices
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function solicitar(array $input): array
    {
        $request = $this->validateRequestInput($input);

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['solicitado_por'], 'solicitado_por');
            $this->assertCompany($request['empresa_id']);

            if (
                $this->authorizations->findActiveByDocumentLineForUpdate(
                    $request['documento_tipo'],
                    $request['documento_id'],
                    $request['documento_partida_id']
                ) !== null
            ) {
                throw new PricingValidationException([
                    'documento_partida_id' =>
                        'Ya existe una autorización activa para esta línea.',
                ]);
            }

            $evaluation = $this->productPrices->evaluarPrecioSolicitado([
                'id_producto' => $request['id_producto'],
                'lista_precio_id' => $request['lista_precio_id'],
                'precio_unitario' => $request['precio_solicitado'],
            ]);

            $this->assertRequestableEvaluation($evaluation);
            $price = $this->prices->findByIdForUpdate(
                (int) $evaluation['producto_precio_id']
            );

            if ($price === null) {
                throw new PricingValidationException([
                    'producto_precio_id' => 'El producto no tiene precio utilizable.',
                ]);
            }

            $authorizationId = $this->authorizations->insert([
                'folio' => $this->authorizationFolio(),
                'empresa_id' => $request['empresa_id'],
                'documento_tipo' => $request['documento_tipo'],
                'documento_id' => $request['documento_id'],
                'documento_partida_id' => $request['documento_partida_id'],
                'documento_folio' => $request['documento_folio'],
                'producto_precio_id' => (int) $evaluation['producto_precio_id'],
                'id_producto' => (string) $evaluation['id_producto'],
                'lista_precio_id' => (int) $evaluation['lista_precio_id'],
                'moneda_id' => (int) $evaluation['moneda_id'],
                'precio_lista_referencia' => (string) $evaluation['precio_lista'],
                'precio_minimo_referencia' => (string) $evaluation['precio_minimo'],
                'precio_solicitado' => (string) $evaluation['precio_unitario'],
                'cantidad' => $request['cantidad'],
                'incluye_impuestos' => (int) $evaluation['incluye_impuestos'],
                'motivo_solicitud' => $request['motivo_solicitud'],
                'estatus' => 'PENDIENTE',
                'solicitado_por' => $request['solicitado_por'],
                'vence_en' => $request['vence_en'],
                'actualizado_por' => $request['solicitado_por'],
            ]);

            return $this->normalizeAuthorization(
                $this->assertAuthorization($authorizationId)
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function aprobar(array $input): array
    {
        return $this->decide($input, 'APROBADA');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function rechazar(array $input): array
    {
        return $this->decide($input, 'RECHAZADA');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function cancelar(array $input): array
    {
        $request = [
            'autorizacion_id' => $this->positiveId(
                $input['autorizacion_id'] ?? null,
                'autorizacion_id'
            ),
            'cancelado_por' => $this->positiveId(
                $input['cancelado_por'] ?? null,
                'cancelado_por'
            ),
            'motivo_cancelacion' => $this->requiredText(
                $input['motivo_cancelacion'] ?? null,
                'motivo_cancelacion',
                1000
            ),
        ];

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['cancelado_por'], 'cancelado_por');
            $authorization = $this->assertAuthorizationForUpdate(
                $request['autorizacion_id']
            );

            if ((string) $authorization['estatus'] !== 'PENDIENTE') {
                throw new PricingValidationException([
                    'autorizacion_id' => 'La autorización no está pendiente.',
                ]);
            }

            $this->authorizations->update($request['autorizacion_id'], [
                'estatus' => 'CANCELADA',
                'cancelado_en' => $this->now(),
                'cancelado_por' => $request['cancelado_por'],
                'motivo_cancelacion' => $request['motivo_cancelacion'],
                'actualizado_en' => $this->now(),
                'actualizado_por' => $request['cancelado_por'],
            ]);

            return $this->normalizeAuthorization(
                $this->assertAuthorization($request['autorizacion_id'])
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function utilizar(array $input): array
    {
        $request = [
            'autorizacion_id' => $this->positiveId(
                $input['autorizacion_id'] ?? null,
                'autorizacion_id'
            ),
            'utilizado_por' => $this->positiveId(
                $input['utilizado_por'] ?? null,
                'utilizado_por'
            ),
        ];

        return $this->transactional(function () use ($request): array {
            $this->assertUser($request['utilizado_por'], 'utilizado_por');
            $authorization = $this->assertAuthorizationForUpdate(
                $request['autorizacion_id']
            );

            if ((string) $authorization['estatus'] !== 'APROBADA') {
                throw new PricingValidationException([
                    'autorizacion_id' => 'La autorización no está aprobada.',
                ]);
            }

            if ($authorization['utilizado_en'] !== null) {
                throw new PricingValidationException([
                    'autorizacion_id' => 'La autorización ya fue utilizada.',
                ]);
            }

            $this->authorizations->update($request['autorizacion_id'], [
                'estatus' => 'UTILIZADA',
                'utilizado_en' => $this->now(),
                'utilizado_por' => $request['utilizado_por'],
                'actualizado_en' => $this->now(),
                'actualizado_por' => $request['utilizado_por'],
            ]);

            return $this->normalizeAuthorization(
                $this->assertAuthorization($request['autorizacion_id'])
            );
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function obtenerUtilizableParaLinea(array $input): ?array
    {
        $line = $this->documentLineInput($input);
        $authorization = $this->authorizations->findUsableByDocumentLine(
            $line['documento_tipo'],
            $line['documento_id'],
            $line['documento_partida_id']
        );

        return $authorization === null
            ? null
            : $this->normalizeAuthorization($authorization);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validarUsoParaPrecio(array $input): array
    {
        $line = $this->documentLineInput($input);
        $idProducto = $this->productId((string) ($input['id_producto'] ?? ''));
        $listId = $this->positiveId($input['lista_precio_id'] ?? null, 'lista_precio_id');
        $unitPrice = $this->money($input['precio_unitario'] ?? null, 'precio_unitario');
        $evaluation = $this->productPrices->evaluarPrecioSolicitado([
            'id_producto' => $idProducto,
            'lista_precio_id' => $listId,
            'precio_unitario' => $unitPrice,
        ]);

        if (($evaluation['resultado'] ?? '') === 'PERMITIDO') {
            return [
                'permitido' => true,
                'requiere_autorizacion' => false,
                'resultado' => 'PERMITIDO',
                'autorizacion' => null,
            ];
        }

        if (($evaluation['resultado'] ?? '') !== 'REQUIERE_AUTORIZACION') {
            return [
                'permitido' => false,
                'requiere_autorizacion' => false,
                'resultado' => (string) ($evaluation['resultado'] ?? 'BLOQUEADO'),
                'autorizacion' => null,
            ];
        }

        $authorization = $this->authorizations->findUsableByDocumentLine(
            $line['documento_tipo'],
            $line['documento_id'],
            $line['documento_partida_id']
        );

        if ($authorization === null) {
            return [
                'permitido' => false,
                'requiere_autorizacion' => true,
                'resultado' => 'AUTORIZACION_REQUERIDA',
                'autorizacion' => null,
            ];
        }

        if (
            (string) $authorization['id_producto'] !== $idProducto
            || (int) $authorization['lista_precio_id'] !== $listId
            || $this->compareMoney((string) $authorization['precio_solicitado'], $unitPrice) !== 0
        ) {
            return [
                'permitido' => false,
                'requiere_autorizacion' => true,
                'resultado' => 'AUTORIZACION_NO_CORRESPONDE',
                'autorizacion' => $this->normalizeAuthorization($authorization),
            ];
        }

        return [
            'permitido' => true,
            'requiere_autorizacion' => true,
            'resultado' => 'AUTORIZADO',
            'autorizacion' => $this->normalizeAuthorization($authorization),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function listar(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $filters = $this->normalizeFilters($filters);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return [
            'rows' => array_map(
                fn (array $row): array => $this->normalizeAuthorization($row),
                $this->authorizations->list($filters, $page, $perPage)
            ),
            'total' => $this->authorizations->count($filters),
            'page' => $page,
            'per_page' => $perPage,
            'filters' => $filters,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ver(int $id): ?array
    {
        $authorization = $this->authorizations->findById(
            $this->positiveIdValue($id, 'autorizacion_id')
        );

        return $authorization === null
            ? null
            : $this->normalizeAuthorization($authorization);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function decide(array $input, string $status): array
    {
        $request = [
            'autorizacion_id' => $this->positiveId(
                $input['autorizacion_id'] ?? null,
                'autorizacion_id'
            ),
            'decidido_por' => $this->positiveId(
                $input['decidido_por'] ?? null,
                'decidido_por'
            ),
            'comentario_decision' => $this->requiredText(
                $input['motivo_decision'] ?? $input['comentario_decision'] ?? null,
                'motivo_decision',
                1000
            ),
        ];

        return $this->transactional(function () use ($request, $status): array {
            $this->assertUser($request['decidido_por'], 'decidido_por');
            $authorization = $this->assertAuthorizationForUpdate(
                $request['autorizacion_id']
            );

            if ((string) $authorization['estatus'] !== 'PENDIENTE') {
                throw new PricingValidationException([
                    'autorizacion_id' => 'La autorización no está pendiente.',
                ]);
            }

            if ((int) $authorization['solicitado_por'] === $request['decidido_por']) {
                throw new PricingValidationException([
                    'decidido_por' =>
                        'El usuario decisor no puede ser el mismo que solicitó la autorización.',
                ]);
            }

            $this->authorizations->update($request['autorizacion_id'], [
                'estatus' => $status,
                'decidido_en' => $this->now(),
                'decidido_por' => $request['decidido_por'],
                'comentario_decision' => $request['comentario_decision'],
                'actualizado_en' => $this->now(),
                'actualizado_por' => $request['decidido_por'],
            ]);

            return $this->normalizeAuthorization(
                $this->assertAuthorization($request['autorizacion_id'])
            );
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = $this->authorizations->beginTransaction();

        try {
            $result = $operation();
            $this->authorizations->commit($ownsTransaction);

            return $result;
        } catch (PricingValidationException $exception) {
            $this->authorizations->rollBack($ownsTransaction);
            throw $exception;
        } catch (PDOException $exception) {
            $this->authorizations->rollBack($ownsTransaction);
            $this->convertDatabaseError($exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->authorizations->rollBack($ownsTransaction);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{empresa_id: int, documento_tipo: string, documento_id: int, documento_partida_id: int, documento_folio: string|null, id_producto: string, lista_precio_id: int, precio_solicitado: string, cantidad: string, solicitado_por: int, motivo_solicitud: string, vence_en: string|null}
     */
    private function validateRequestInput(array $input): array
    {
        return [
            'empresa_id' => $this->positiveId($input['empresa_id'] ?? null, 'empresa_id'),
            'documento_tipo' => $this->documentType($input['documento_tipo'] ?? null),
            'documento_id' => $this->positiveId($input['documento_id'] ?? null, 'documento_id'),
            'documento_partida_id' => $this->positiveId(
                $input['documento_partida_id'] ?? $input['linea_id'] ?? null,
                'documento_partida_id'
            ),
            'documento_folio' => $this->optionalText($input['documento_folio'] ?? null, 40),
            'id_producto' => $this->productId((string) ($input['id_producto'] ?? '')),
            'lista_precio_id' => $this->positiveId(
                $input['lista_precio_id'] ?? null,
                'lista_precio_id'
            ),
            'precio_solicitado' => $this->money(
                $input['precio_unitario_solicitado']
                    ?? $input['precio_solicitado']
                    ?? null,
                'precio_unitario_solicitado'
            ),
            'cantidad' => $this->positiveQuantity($input['cantidad'] ?? '1'),
            'solicitado_por' => $this->positiveId(
                $input['solicitado_por'] ?? null,
                'solicitado_por'
            ),
            'motivo_solicitud' => $this->requiredText(
                $input['motivo_solicitud'] ?? null,
                'motivo_solicitud',
                1000
            ),
            'vence_en' => $this->optionalDateTime($input['vence_en'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{documento_tipo: string, documento_id: int, documento_partida_id: int}
     */
    private function documentLineInput(array $input): array
    {
        return [
            'documento_tipo' => $this->documentType($input['documento_tipo'] ?? null),
            'documento_id' => $this->positiveId($input['documento_id'] ?? null, 'documento_id'),
            'documento_partida_id' => $this->positiveId(
                $input['documento_partida_id'] ?? $input['linea_id'] ?? null,
                'documento_partida_id'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $evaluation
     */
    private function assertRequestableEvaluation(array $evaluation): void
    {
        $result = (string) ($evaluation['resultado'] ?? '');

        if ($result === 'REQUIERE_AUTORIZACION') {
            return;
        }

        $messages = [
            'PERMITIDO' => 'El precio solicitado no requiere autorización.',
            'BLOQUEADO' => 'El precio solicitado está por debajo del precio mínimo.',
            'SIN_PRECIO' => 'El producto no tiene precio utilizable.',
            'PRECIO_EN_REVISION' => 'El precio del producto está pendiente de revisión.',
            'SIN_PRECIO_UTILIZABLE' => 'El producto no tiene precio utilizable.',
        ];

        throw new PricingValidationException([
            'precio_unitario_solicitado' =>
                $messages[$result] ?? 'El precio solicitado no puede autorizarse.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertAuthorization(int $id): array
    {
        $authorization = $this->authorizations->findById($id);

        if ($authorization === null) {
            throw new PricingValidationException([
                'autorizacion_id' => 'La autorización no existe.',
            ]);
        }

        return $authorization;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertAuthorizationForUpdate(int $id): array
    {
        $authorization = $this->authorizations->findByIdForUpdate($id);

        if ($authorization === null) {
            throw new PricingValidationException([
                'autorizacion_id' => 'La autorización no existe.',
            ]);
        }

        return $authorization;
    }

    private function assertUser(int $userId, string $field): void
    {
        if (!$this->authorizations->activeUserExists($userId)) {
            throw new PricingValidationException([
                $field => 'El usuario no existe o no está activo.',
            ]);
        }
    }

    private function assertCompany(int $companyId): void
    {
        if (!$this->authorizations->activeCompanyExists($companyId)) {
            throw new PricingValidationException([
                'empresa_id' => 'La empresa no existe o no está activa.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $authorization
     * @return array<string, mixed>
     */
    private function normalizeAuthorization(array $authorization): array
    {
        return [
            'id' => (int) $authorization['id'],
            'folio' => (string) $authorization['folio'],
            'empresa_id' => (int) $authorization['empresa_id'],
            'documento_tipo' => (string) $authorization['documento_tipo'],
            'documento_id' => (int) $authorization['documento_id'],
            'documento_partida_id' => (int) $authorization['documento_partida_id'],
            'documento_folio' => $authorization['documento_folio'] ?? null,
            'producto_precio_id' => (int) $authorization['producto_precio_id'],
            'id_producto' => (string) $authorization['id_producto'],
            'lista_precio_id' => (int) $authorization['lista_precio_id'],
            'moneda_id' => (int) $authorization['moneda_id'],
            'precio_lista_referencia' => (string) $authorization['precio_lista_referencia'],
            'precio_minimo_referencia' => (string) $authorization['precio_minimo_referencia'],
            'precio_solicitado' => (string) $authorization['precio_solicitado'],
            'cantidad' => (string) $authorization['cantidad'],
            'incluye_impuestos' => (int) $authorization['incluye_impuestos'],
            'motivo_solicitud' => (string) $authorization['motivo_solicitud'],
            'estatus' => (string) $authorization['estatus'],
            'solicitado_en' => (string) $authorization['solicitado_en'],
            'solicitado_por' => (int) $authorization['solicitado_por'],
            'decidido_en' => $authorization['decidido_en'] ?? null,
            'decidido_por' => $authorization['decidido_por'] === null
                ? null
                : (int) $authorization['decidido_por'],
            'comentario_decision' => $authorization['comentario_decision'] ?? null,
            'vence_en' => $authorization['vence_en'] ?? null,
            'cancelado_en' => $authorization['cancelado_en'] ?? null,
            'cancelado_por' => $authorization['cancelado_por'] === null
                ? null
                : (int) $authorization['cancelado_por'],
            'motivo_cancelacion' => $authorization['motivo_cancelacion'] ?? null,
            'utilizado_en' => $authorization['utilizado_en'] ?? null,
            'utilizado_por' => $authorization['utilizado_por'] === null
                ? null
                : (int) $authorization['utilizado_por'],
            ...array_filter([
                'lista_clave' => $authorization['lista_clave'] ?? null,
                'moneda_codigo' => $authorization['moneda_codigo'] ?? null,
                'producto_descripcion' => $authorization['producto_descripcion'] ?? null,
                'solicitado_por_username' => $authorization['solicitado_por_username'] ?? null,
                'decidido_por_username' => $authorization['decidido_por_username'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (isset($filters['estatus'])) {
            $status = strtoupper(trim((string) $filters['estatus']));

            if (in_array($status, self::VALID_STATUSES, true)) {
                $normalized['estatus'] = $status;
            }
        }

        if (isset($filters['documento_tipo'])) {
            $normalized['documento_tipo'] = $this->documentType(
                $filters['documento_tipo']
            );
        }

        if (isset($filters['id_producto']) && trim((string) $filters['id_producto']) !== '') {
            $normalized['id_producto'] = $this->productId(
                (string) $filters['id_producto']
            );
        }

        foreach ([
            'documento_id',
            'documento_partida_id',
            'lista_precio_id',
            'solicitado_por',
            'decidido_por',
            'empresa_id',
        ] as $field) {
            if (isset($filters[$field]) && (string) $filters[$field] !== '') {
                $normalized[$field] = $this->positiveId($filters[$field], $field);
            }
        }

        if (isset($filters['utilizado']) && in_array((string) $filters['utilizado'], ['yes', 'no'], true)) {
            $normalized['utilizado'] = (string) $filters['utilizado'];
        }

        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (isset($filters[$field]) && trim((string) $filters[$field]) !== '') {
                $normalized[$field] = trim((string) $filters[$field]);
            }
        }

        return $normalized;
    }

    private function documentType(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PricingValidationException([
                'documento_tipo' => 'El tipo de documento es obligatorio.',
            ]);
        }

        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9_]{3,32}$/', $value) !== 1) {
            throw new PricingValidationException([
                'documento_tipo' => 'El tipo de documento no es válido.',
            ]);
        }

        return $value;
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

    private function positiveQuantity(mixed $value): string
    {
        $quantity = $this->money($value, 'cantidad');

        if ($this->compareMoney($quantity, '0.0000') <= 0) {
            throw new PricingValidationException([
                'cantidad' => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        return $quantity;
    }

    private function requiredText(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new PricingValidationException([
                $field => 'El campo es obligatorio.',
            ]);
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new PricingValidationException([
                $field => 'El campo es obligatorio y excede la longitud permitida.',
            ]);
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new PricingValidationException([
                'documento_folio' => 'El texto excede la longitud permitida.',
            ]);
        }

        return $value;
    }

    private function optionalDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value)
            !== 1
        ) {
            throw new PricingValidationException([
                'vence_en' => 'La fecha no es válida.',
            ]);
        }

        return strlen($value) === 10 ? $value . ' 23:59:59' : $value;
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

    private function authorizationFolio(): string
    {
        return 'AUT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function convertDatabaseError(PDOException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new PricingValidationException([
                'documento_partida_id' =>
                    'Ya existe una autorización activa para esta línea.',
            ]);
        }
    }
}

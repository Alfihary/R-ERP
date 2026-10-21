<?php

declare(strict_types=1);

namespace App\Domain\Products\History;

use InvalidArgumentException;

/**
 * Maps repository rows to the product-history read model without SQL or HTML.
 *
 * @param array<string, array<string, mixed>> $registry
 */
final class ProductHistoryMapper
{
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';
    public const UNAVAILABLE = 'unavailable';

    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'token', 'access_token', 'refresh_token',
        'authorization', 'cookie', 'session', 'session_id', 'csrf', 'secret',
        'api_key', 'private_key',
    ];

    private const PATH_KEYS = [
        'path', 'realpath', 'storage_path', 'temp_path', 'temporary_path',
        'file_path',
    ];

    /**
     * @param array<string, array<string, mixed>> $registry
     */
    public function __construct(
        private readonly array $registry,
        private readonly ProductHistoryPresenter $presenter
    ) {
    }

    public function map(array $row, string $requestedProductId): ProductHistoryItem
    {
        $action = (string) ($row['action'] ?? '');
        $event = $this->registry[$action] ?? null;
        $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);
        $completeness = $metadata === null ? self::UNAVAILABLE : self::PARTIAL;
        if ($event === null) {
            $completeness = self::UNAVAILABLE;
        }

        $productId = $this->resolveProductId($row, $metadata, $event, $requestedProductId, $completeness);
        $presentation = $this->presenter->present(
            $action,
            is_array($event) && is_string($event['subresource'] ?? null)
                ? $event['subresource']
                : null
        );

        if (is_array($event) && $metadata !== null) {
            $completeness = $this->contractCompleteness($action, $event, $metadata, $completeness);
        }

        $actorId = $row['actor_id'] === null ? null : (int) $row['actor_id'];
        $actorUsername = trim((string) ($row['actor_username'] ?? ''));
        $actorDisplay = $actorId === null
            ? 'Sistema'
            : ($actorUsername !== '' ? $actorUsername : 'Usuario eliminado (#' . $actorId . ')');

        $safeMetadata = $metadata === null ? null : $this->sanitize($metadata);
        $before = $this->mapState($metadata, 'before');
        $after = $this->mapState($metadata, 'after');
        $changedFields = $this->mapChangedFields($metadata);

        if ($event !== null && ($event['subresource'] ?? null) === 'kit_componente') {
            [$before, $after] = $this->mapKitState($metadata, $before, $after);
        }

        return new ProductHistoryItem(
            auditId: (int) ($row['audit_id'] ?? 0),
            occurredAt: (string) ($row['occurred_at'] ?? ''),
            action: $action,
            category: $presentation['category'],
            subresource: $presentation['subresource'],
            result: (string) ($row['result'] ?? ''),
            actorId: $actorId,
            actorDisplay: $actorDisplay,
            productId: $productId,
            entity: (string) ($row['entity'] ?? ''),
            entityId: $row['entity_id'] === null ? null : (string) $row['entity_id'],
            summary: $presentation['summary'],
            changedFields: $changedFields,
            before: $before,
            after: $after,
            metadata: $safeMetadata,
            completeness: $completeness,
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeMetadata(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $metadata @param array<string,mixed>|null $event */
    private function resolveProductId(
        array $row,
        ?array $metadata,
        ?array $event,
        string $requestedProductId,
        string &$completeness
    ): string {
        $entityId = $row['entity_id'] === null ? null : (string) $row['entity_id'];
        $metadataId = is_array($metadata) && isset($metadata['producto_id'])
            ? (string) $metadata['producto_id']
            : null;
        $isSubresource = is_array($event) && ($event['subresource'] ?? null) !== null;
        $resolved = $isSubresource ? $metadataId : ($entityId ?? $metadataId);

        if ($entityId !== null && $metadataId !== null && $entityId !== $metadataId) {
            $completeness = self::PARTIAL;
        }
        if ($resolved === null || $resolved === '') {
            $completeness = self::UNAVAILABLE;
            return $requestedProductId;
        }
        return $resolved;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $metadata */
    private function contractCompleteness(string $action, array $event, array $metadata, string $current): string
    {
        if ($current === self::UNAVAILABLE) {
            return self::UNAVAILABLE;
        }
        if (($event['subresource'] ?? null) !== null
            && ($event['contract'] ?? null) !== 'CANONICAL_V1'
        ) {
            return self::PARTIAL;
        }
        if ($metadata === [] || !$this->hasRequiredFields($action, $event, $metadata)) {
            return self::PARTIAL;
        }
        return self::COMPLETE;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $metadata */
    private function hasRequiredFields(string $action, array $event, array $metadata): bool
    {
        if (!array_key_exists('version_contrato', $metadata)
            || !array_key_exists('producto_id', $metadata)
        ) {
            return false;
        }

        $subresource = $event['subresource'] ?? null;
        if ($subresource === null) {
            if ($action === 'productos.crear') {
                return is_array($metadata['changed_fields'] ?? null)
                    && is_array($metadata['after'] ?? null);
            }
            if ($action === 'productos.actualizar') {
                return is_array($metadata['changed_fields'] ?? null)
                    && is_array($metadata['before'] ?? null)
                    && is_array($metadata['after'] ?? null);
            }
            if (in_array($action, ['productos.activar', 'productos.desactivar'], true)) {
                return is_array($metadata['changed_fields'] ?? null)
                    && in_array('activo', $metadata['changed_fields'], true)
                    && is_array($metadata['before'] ?? null)
                    && is_array($metadata['after'] ?? null);
            }
            return false;
        }

        if ($subresource === 'imagen') {
            $required = match ($action) {
                'producto_imagen.subir' => ['id_documento_nuevo', 'mime', 'tamano_bytes'],
                'producto_imagen.reemplazar' => ['id_documento_nuevo', 'id_documento_anterior', 'mime', 'tamano_bytes'],
                'producto_imagen.eliminar' => ['id_documento_anterior'],
                default => [],
            };
            return $this->hasKeys($metadata, $required);
        }
        if ($subresource === 'kit_componente') {
            $required = ['kit_id_producto', 'componente_id_producto'];
            if ($action === 'productos.kits.componentes.actualizar') {
                $required[] = 'cantidad_before';
            }
            if (in_array($action, [
                'productos.kits.componentes.agregar',
                'productos.kits.componentes.actualizar',
                'productos.kits.componentes.reactivar',
            ], true)) {
                $required[] = 'cantidad_after';
            }
            if ($action === 'productos.kits.componentes.quitar') {
                $required[] = 'cantidad_before';
            }
            return $this->hasKeys($metadata, $required);
        }
        if ($subresource === 'similar') {
            return $this->hasKeys($metadata, [
                'producto_a_id', 'producto_b_id', 'producto_relacionado_id',
            ]);
        }
        if ($subresource === 'variante') {
            return $this->hasKeys($metadata, ['familia_id']);
        }
        return false;
    }

    /** @param array<string,mixed> $metadata @param list<string> $keys */
    private function hasKeys(array $metadata, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metadata)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed>|null $metadata */
    private function mapChangedFields(?array $metadata): ?array
    {
        if (!is_array($metadata) || !is_array($metadata['changed_fields'] ?? null)) {
            return null;
        }
        $fields = [];
        foreach ($metadata['changed_fields'] as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }
        return $fields === [] ? null : array_values(array_unique($fields));
    }

    /** @param array<string,mixed>|null $metadata @return array<string,mixed>|null */
    private function mapState(?array $metadata, string $key): ?array
    {
        if (!is_array($metadata) || !array_key_exists($key, $metadata)) {
            return null;
        }
        return is_array($metadata[$key]) ? $this->sanitize($metadata[$key]) : null;
    }

    /** @param array<string,mixed>|null $metadata @param array<string,mixed>|null $before @param array<string,mixed>|null $after @return array{0:array<string,mixed>|null,1:array<string,mixed>|null} */
    private function mapKitState(?array $metadata, ?array $before, ?array $after): array
    {
        if (!is_array($metadata)) {
            return [$before, $after];
        }
        if (array_key_exists('cantidad_before', $metadata)) {
            $before = ['quantity' => $this->decimalString($metadata['cantidad_before'])];
        }
        if (array_key_exists('cantidad_after', $metadata)) {
            $after = ['quantity' => $this->decimalString($metadata['cantidad_after'])];
        }
        foreach (['kit_id_producto', 'componente_id_producto'] as $key) {
            if (array_key_exists($key, $metadata) && is_scalar($metadata[$key])) {
                if ($before !== null) $before[$key] = (string) $metadata[$key];
                if ($after !== null) $after[$key] = (string) $metadata[$key];
            }
        }
        return [$before, $after];
    }

    private function decimalString(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function sanitize(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)
                || in_array($normalizedKey, self::PATH_KEYS, true)
            ) {
                continue;
            }
            if (is_array($item)) {
                $result[(string) $key] = $this->sanitize($item);
            } elseif (is_scalar($item) || $item === null) {
                $result[(string) $key] = $item;
            } else {
                $result[(string) $key] = '[unavailable]';
            }
        }
        return $result;
    }
}

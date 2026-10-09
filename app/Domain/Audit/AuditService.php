<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Infrastructure\Repositories\AuditRepository;
use Throwable;

final class AuditService implements AuditRecorderInterface
{
    private const REDACTED = '[REDACTED]';

    /**
     * Keys consumed as first-class auditoria_eventos columns.
     */
    private const RESERVED_METADATA_KEYS = [
        'entidad',
        'entidad_id',
        'resultado',
        'ip',
        'user_agent',
    ];

    public function __construct(private readonly AuditRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(string $action, ?int $actorUserId = null, array $metadata = []): void
    {
        $this->write($action, $actorUserId, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordPublic(string $action, array $metadata = []): void
    {
        $this->write($action, null, $metadata);
    }

    /**
     * Write an audited event as part of an owning transaction.
     * Unlike record(), storage failures are allowed to abort the operation.
     * @param array<string, mixed> $metadata
     */
    public function recordRequired(string $action, ?int $actorUserId, array $metadata = []): void
    {
        $entity = $this->safeColumnString($metadata['entidad'] ?? 'usuarios', 80) ?? 'usuarios';
        $entityId = $this->safeNullableColumnString($metadata['entidad_id'] ?? null, 64);
        $result = $this->safeColumnString($metadata['resultado'] ?? 'ok', 32) ?? 'ok';
        $ip = $this->safeIp($metadata['ip'] ?? null);
        $userAgent = $this->safeUserAgent($metadata['user_agent'] ?? null);
        $clean = $this->sanitizeMetadata($metadata);
        $this->repository->insertRequired(
            $actorUserId,
            $this->safeColumnString($action, 120) ?? 'evento.desconocido',
            $entity,
            $entityId,
            $result,
            $ip,
            $userAgent,
            $clean === [] ? null : $clean
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function write(string $action, ?int $actorUserId, array $metadata): void
    {
        try {
            $entity = $this->safeColumnString($metadata['entidad'] ?? 'sistema', 80) ?? 'sistema';
            $entityId = $this->safeNullableColumnString($metadata['entidad_id'] ?? null, 64);
            $result = $this->safeColumnString($metadata['resultado'] ?? 'ok', 32) ?? 'ok';
            $ip = $this->safeIp($metadata['ip'] ?? null);
            $userAgent = $this->safeUserAgent($metadata['user_agent'] ?? null);
            $metadataJson = $this->sanitizeMetadata($metadata);

            $this->repository->insert(
                $actorUserId,
                $this->safeColumnString($action, 120) ?? 'evento.desconocido',
                $entity,
                $entityId,
                $result,
                $ip,
                $userAgent,
                $metadataJson === [] ? null : $metadataJson
            );
        } catch (Throwable) {
            // La auditoría no debe romper el flujo funcional principal.
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = $this->safeMetadataKey((string) $key);

            if ($normalizedKey === null || in_array($normalizedKey, self::RESERVED_METADATA_KEYS, true)) {
                continue;
            }

            $clean[$normalizedKey] = $this->sanitizeValue($normalizedKey, $value, 0);
        }

        return $clean;
    }

    private function safeIp(mixed $ip): ?string
    {
        if (!is_string($ip)) {
            return null;
        }

        $ip = trim(explode(',', $ip)[0] ?? '');

        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        if (preg_match('/^[A-Fa-f0-9:.]{3,45}$/', $ip) !== 1) {
            return null;
        }

        return $ip;
    }

    private function safeUserAgent(mixed $userAgent): ?string
    {
        if (!is_string($userAgent) || trim($userAgent) === '') {
            return null;
        }

        return substr(str_replace(["\r", "\n"], ' ', $userAgent), 0, 500);
    }

    private function safeColumnString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function safeNullableColumnString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->safeColumnString($value, $maxLength);
    }

    private function safeMetadataKey(string $key): ?string
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        return substr($key, 0, 80);
    }

    private function sanitizeValue(string $key, mixed $value, int $depth): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if ($depth >= 3) {
            return '[TRUNCATED]';
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $childKey => $childValue) {
                $safeKey = $this->safeMetadataKey((string) $childKey);

                if ($safeKey === null) {
                    continue;
                }

                $clean[$safeKey] = $this->sanitizeValue($safeKey, $childValue, $depth + 1);
            }

            return $clean;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return '[UNSUPPORTED]';
        }

        $text = str_replace(["\r", "\n"], ' ', (string) $value);

        if ($this->looksSensitive($text)) {
            return self::REDACTED;
        }

        return substr($text, 0, 500);
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        $sensitiveFragments = [
            'token',
            'hash',
            'password',
            'clave',
            'secret',
            'authorization',
            'cookie',
            'session',
            'csrf',
            'ruta',
            'path',
            'storage',
            'url',
            'uri',
        ];

        foreach ($sensitiveFragments as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function looksSensitive(string $text): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $text) === 1
            || preg_match('#/credencial/verificar/[a-f0-9]{64}#i', $text) === 1
            || str_contains(strtolower($text), 'storage/uploads')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $text) === 1
            || preg_match('#^/(home|var|tmp|etc|srv|opt|users|storage)/#i', $text) === 1;
    }
}

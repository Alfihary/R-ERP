<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class AuditQueryRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     filters: array<string, mixed>
     * }
     */
    public function search(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        $where = [];
        $params = [];

        if ($normalized['accion'] !== '') {
            $where[] = 'a.accion = :accion';
            $params['accion'] = $normalized['accion'];
        }

        if ($normalized['resultado'] !== '') {
            $where[] = 'a.resultado = :resultado';
            $params['resultado'] = $normalized['resultado'];
        }

        if ($normalized['actor_usuario_id'] !== null) {
            $where[] = 'a.actor_usuario_id = :actor_usuario_id';
            $params['actor_usuario_id'] = $normalized['actor_usuario_id'];
        }

        if ($normalized['fecha_desde'] !== '') {
            $where[] = 'a.creado_en >= :fecha_desde';
            $params['fecha_desde'] = $normalized['fecha_desde'] . ' 00:00:00';
        }

        if ($normalized['fecha_hasta'] !== '') {
            $where[] = 'a.creado_en <= :fecha_hasta';
            $params['fecha_hasta'] = $normalized['fecha_hasta'] . ' 23:59:59';
        }

        if ($normalized['q'] !== '') {
            $where[] = '(
                a.accion LIKE :q_accion
                OR a.entidad LIKE :q_entidad
                OR a.resultado LIKE :q_resultado
                OR CAST(a.metadata_json AS CHAR) LIKE :q_metadata
            )';
            $query = '%' . $this->escapeLike($normalized['q']) . '%';
            $params['q_accion'] = $query;
            $params['q_entidad'] = $query;
            $params['q_resultado'] = $query;
            $params['q_metadata'] = $query;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = $this->count($whereSql, $params);
        $offset = ($normalized['page'] - 1) * $normalized['per_page'];

        $statement = $this->connection->pdo()->prepare(
            <<<SQL
            SELECT
                a.id,
                a.actor_usuario_id,
                u.username AS actor_username,
                a.accion,
                a.entidad,
                a.entidad_id,
                a.resultado,
                a.ip,
                a.user_agent,
                a.metadata_json,
                a.creado_en
            FROM auditoria_eventos a
            LEFT JOIN usuarios u
                ON u.id = a.actor_usuario_id
            {$whereSql}
            ORDER BY a.creado_en DESC, a.id DESC
            LIMIT :limit OFFSET :offset
            SQL
        );

        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->bindValue(':limit', $normalized['per_page'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = array_map(
            fn (array $row): array => $this->sanitizeRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $normalized['page'],
            'per_page' => $normalized['per_page'],
            'filters' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $whereSql, array $params): int
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT COUNT(*) FROM auditoria_eventos a {$whereSql}"
        );

        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     accion: string,
     *     resultado: string,
     *     actor_usuario_id: int|null,
     *     fecha_desde: string,
     *     fecha_hasta: string,
     *     q: string,
     *     page: int,
     *     per_page: int
     * }
     */
    private function normalizeFilters(array $filters): array
    {
        $actor = filter_var(
            $filters['actor_usuario_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $page = filter_var(
            $filters['page'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $perPage = filter_var(
            $filters['per_page'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );

        return [
            'accion' => $this->safeText($filters['accion'] ?? '', 120),
            'resultado' => $this->safeText($filters['resultado'] ?? '', 32),
            'actor_usuario_id' => $actor === false ? null : $actor,
            'fecha_desde' => $this->safeDate($filters['fecha_desde'] ?? ''),
            'fecha_hasta' => $this->safeDate($filters['fecha_hasta'] ?? ''),
            'q' => $this->safeText($filters['q'] ?? '', 120),
            'page' => $page === false ? 1 : $page,
            'per_page' => $perPage === false ? 25 : $perPage,
        ];
    }

    private function safeText(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return substr(trim((string) $value), 0, $maxLength);
    }

    private function safeDate(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeRow(array $row): array
    {
        $row['metadata_json'] = $this->sanitizeMetadataString($row['metadata_json'] ?? null);
        $row['user_agent'] = $this->sanitizeText((string) ($row['user_agent'] ?? ''));

        return $row;
    }

    private function sanitizeMetadataString(mixed $metadata): string
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            return '';
        }

        $decoded = json_decode($metadata, true);

        if (is_array($decoded)) {
            return json_encode(
                $this->sanitizeMetadataValue($decoded),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        return $this->sanitizeText($metadata);
    }

    private function sanitizeMetadataValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $key = (string) $key;
                $clean[$key] = $this->sensitiveKey($key)
                    ? '[REDACTED]'
                    : $this->sanitizeMetadataValue($item);
            }

            return $clean;
        }

        if (!is_scalar($value) && $value !== null) {
            return '[UNSUPPORTED]';
        }

        if (is_string($value)) {
            return $this->sanitizeText($value);
        }

        return $value;
    }

    private function sanitizeText(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        if (
            preg_match('/^[a-f0-9]{64}$/i', $value) === 1
            || preg_match('#/credencial/verificar/[a-f0-9]{64}#i', $value) === 1
            || str_contains(strtolower($value), 'storage/uploads')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $value) === 1
            || preg_match('#^/(home|var|tmp|etc|srv|opt|users|storage)/#i', $value) === 1
        ) {
            return '[REDACTED]';
        }

        return substr($value, 0, 500);
    }

    private function sensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ([
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
        ] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class MailOutboxQueryRepository
{
    public const STATUSES = ['PENDIENTE', 'ENVIANDO', 'ENVIADO', 'ERROR', 'CANCELADO'];

    public const EVENTS = [
        'TICKET_CREADO',
        'PARTIDA_APROBADA',
        'PARTIDA_RECHAZADA',
        'TICKET_RESUELTO_TOTAL',
        'TICKET_RESUELTO_PARCIAL',
        'TICKET_CANCELADO',
    ];

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $warehouseIds
     * @return array<string, mixed>
     */
    public function search(array $filters, array $warehouseIds): array
    {
        $normalized = $this->normalizeFilters($filters);
        [$whereSql, $params] = $this->where($normalized, $warehouseIds);
        $total = $this->count($whereSql, $params);
        $pages = max(1, (int) ceil($total / $normalized['per_page']));
        $normalized['page'] = min($normalized['page'], $pages);
        $offset = ($normalized['page'] - 1) * $normalized['per_page'];

        $statement = $this->connection->pdo()->prepare(
            <<<SQL
            SELECT
                m.id,
                m.ticket_id,
                tp.folio,
                tp.deleted_at AS ticket_deleted_at,
                m.partida_id,
                p.numero_partida,
                m.evento,
                m.status,
                m.destinatario_email,
                m.intentos,
                m.max_intentos,
                m.ultimo_intento_at,
                m.enviado_at,
                m.created_at
            FROM tickets_productos_correos m
            INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
            LEFT JOIN tickets_productos_partidas p ON p.id = m.partida_id
            {$whereSql}
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT :limit OFFSET :offset
            SQL
        );
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $normalized['per_page'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'rows' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $normalized['page'],
            'per_page' => $normalized['per_page'],
            'pages' => $pages,
            'filters' => $normalized,
            'validation_errors' => $this->validationErrors($filters),
        ];
    }

    /**
     * @param list<int> $warehouseIds
     * @return array<string, int>
     */
    public function countByStatus(array $warehouseIds): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        $statement = $this->connection->pdo()->prepare(
            "SELECT m.status, COUNT(*) AS total
             FROM tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             WHERE {$scopeSql}
             GROUP BY m.status"
        );
        $this->bind($statement, $params);
        $statement->execute();

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @param list<int> $warehouseIds
     * @return array<string, mixed>|null
     */
    public function findDetail(int $id, array $warehouseIds): ?array
    {
        if ($id < 1) {
            return null;
        }

        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        $params['id'] = $id;
        $statement = $this->connection->pdo()->prepare(
            "SELECT
                m.*,
                tp.folio,
                tp.estado AS ticket_estado,
                tp.deleted_at AS ticket_deleted_at,
                tp.empresa_id,
                e.nombre AS empresa_nombre,
                tp.almacen_id,
                a.nombre AS almacen_nombre,
                p.numero_partida,
                p.descripcion AS partida_descripcion
             FROM tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             LEFT JOIN empresas e ON e.id = tp.empresa_id
             LEFT JOIN almacenes a ON a.id = tp.almacen_id
             LEFT JOIN tickets_productos_partidas p ON p.id = m.partida_id
             WHERE m.id = :id AND {$scopeSql}
             LIMIT 1"
        );
        $this->bind($statement, $params);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $row['recipients'] = $this->decodeRecipients(
            (string) ($row['destinatario_email'] ?? ''),
            $row['cc_json'] ?? null
        );
        $row['ticket_link_allowed'] = $row['ticket_deleted_at'] === null;

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $warehouseIds
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function where(array $filters, array $warehouseIds): array
    {
        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        $where = [$scopeSql];

        if ($filters['estado'] !== '') {
            $where[] = 'm.status = :estado';
            $params['estado'] = $filters['estado'];
        }
        if ($filters['evento'] !== '') {
            $where[] = 'm.evento = :evento';
            $params['evento'] = $filters['evento'];
        }
        if ($filters['folio'] !== '') {
            $where[] = "tp.folio LIKE :folio ESCAPE '='";
            $params['folio'] = '%' . $this->escapeLike($filters['folio']) . '%';
        }
        if ($filters['ticket_id'] !== null) {
            $where[] = 'm.ticket_id = :ticket_id';
            $params['ticket_id'] = $filters['ticket_id'];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param list<int> $warehouseIds
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function scopeWhere(array $warehouseIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $warehouseIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['1 = 0', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = 'scope_warehouse_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        return ['tp.almacen_id IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /** @param array<string, int|string> $params */
    private function count(string $whereSql, array $params): int
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT COUNT(*)
             FROM tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             {$whereSql}"
        );
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $status = $this->scalarText($filters['estado'] ?? '', 20);
        $event = $this->scalarText($filters['evento'] ?? '', 40);
        $page = $this->positiveInteger($filters['page'] ?? null) ?? 1;
        $perPage = $this->positiveInteger($filters['per_page'] ?? null);

        return [
            'estado' => in_array($status, self::STATUSES, true) ? $status : '',
            'evento' => in_array($event, self::EVENTS, true) ? $event : '',
            'folio' => $this->scalarText($filters['folio'] ?? '', 80),
            'ticket_id' => $this->positiveInteger($filters['ticket_id'] ?? null),
            'page' => $page,
            'per_page' => in_array($perPage, [25, 50, 100], true) ? $perPage : 25,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function validationErrors(array $filters): array
    {
        $errors = [];
        $status = $this->scalarText($filters['estado'] ?? '', 20);
        $event = $this->scalarText($filters['evento'] ?? '', 40);

        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            $errors[] = 'El estado indicado no es válido y fue ignorado.';
        }
        if ($event !== '' && !in_array($event, self::EVENTS, true)) {
            $errors[] = 'El evento indicado no es válido y fue ignorado.';
        }
        if (($filters['ticket_id'] ?? '') !== '' && $this->positiveInteger($filters['ticket_id']) === null) {
            $errors[] = 'El ID de ticket debe ser un entero positivo y fue ignorado.';
        }
        if (($filters['page'] ?? '') !== '' && $this->positiveInteger($filters['page']) === null) {
            $errors[] = 'La página solicitada no es válida; se mostró la primera.';
        }

        return $errors;
    }

    /** @return array{available: bool, to: list<string>, cc: list<string>, bcc: list<string>} */
    private function decodeRecipients(string $primary, mixed $json): array
    {
        $groups = ['to' => [], 'cc' => [], 'bcc' => []];
        $seen = [];
        $this->appendEmail($groups['to'], $seen, $primary);

        if ($json === null || $json === '') {
            return ['available' => true] + $groups;
        }

        try {
            $decoded = is_string($json) ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            return ['available' => false] + $groups;
        }

        if (!is_array($decoded)) {
            return ['available' => false] + $groups;
        }

        if (array_is_list($decoded)) {
            $decoded = ['cc' => $decoded];
        }

        $available = true;
        foreach (['to', 'cc', 'bcc'] as $group) {
            $values = $decoded[$group] ?? [];
            if (!is_array($values)) {
                $available = false;
                continue;
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    $available = false;
                    continue;
                }
                $this->appendEmail($groups[$group], $seen, $value);
            }
        }

        return ['available' => $available] + $groups;
    }

    /** @param list<string> $target @param array<string, bool> $seen */
    private function appendEmail(array &$target, array &$seen, string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seen[$email])) {
            return;
        }
        $seen[$email] = true;
        $target[] = $email;
    }

    private function scalarText(mixed $value, int $maxLength): string
    {
        return is_scalar($value) ? substr(trim((string) $value), 0, $maxLength) : '';
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    /** @param array<string, int|string> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}

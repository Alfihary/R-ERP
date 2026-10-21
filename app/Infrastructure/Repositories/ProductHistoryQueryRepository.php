<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Products\History\ProductHistoryFilters;
use App\Infrastructure\Database\ConnectionProvider;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Read-only SQL boundary for product history. Metadata mapping belongs to 2B.
 */
final class ProductHistoryQueryRepository
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE = 100;

    /**
     * @param array<string, array<string, mixed>> $registry
     */
    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly array $registry
    )
    {
    }

    /**
     * @return array{
     *     rows: list<array{
     *         audit_id:int,
     *         occurred_at:string,
     *         action:string,
     *         result:string,
     *         actor_id:int|null,
     *         actor_username:string|null,
     *         entity:string,
     *         entity_id:string|null,
     *         metadata_json:string|null
     *     }>,
     *     total:int,
     *     page:int,
     *     page_size:int,
     *     pages:int
     * }
     */
    public function paginateForProduct(
        string $productId,
        ProductHistoryFilters $filters,
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): array {
        $productId = trim($productId);
        if ($productId === '') {
            throw new InvalidArgumentException('A product ID is required.');
        }

        $page = max(1, $page);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));
        if ($page > intdiv(PHP_INT_MAX, $pageSize) + 1) {
            throw new InvalidArgumentException('History page is too large.');
        }

        $registry = $this->registry;
        $this->assertFilterContract($filters, $registry);

        $params = [];
        $branches = [];
        $directActions = $this->directActions($filters, $registry);
        if ($directActions !== []) {
            $branches[] = $this->branch(
                $productId,
                $filters,
                $directActions,
                [],
                'direct',
                $params
            );
        }

        $subresourceActions = $this->subresourceActions($filters, $registry);
        if ($subresourceActions !== []) {
            $branches[] = $this->branch(
                $productId,
                $filters,
                array_keys($subresourceActions),
                $subresourceActions,
                'subresource',
                $params
            );
        }

        $dataset = $branches === []
            ? 'SELECT NULL AS audit_id, NULL AS occurred_at, NULL AS action,
                      NULL AS result, NULL AS actor_id, NULL AS actor_username,
                      NULL AS entity, NULL AS entity_id, NULL AS metadata_json
                 WHERE 1 = 0'
            : implode("\nUNION ALL\n", $branches);

        $countStatement = $this->connection->pdo()->prepare(
            "SELECT COUNT(*) FROM ({$dataset}) history_rows"
        );
        $this->bindParams($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $pageParams = $params;
        $pageParams['history_limit'] = $pageSize;
        $pageParams['history_offset'] = $offset;
        $pageStatement = $this->connection->pdo()->prepare(
            "SELECT * FROM ({$dataset}) history_rows
             ORDER BY occurred_at DESC, audit_id DESC
             LIMIT :history_limit OFFSET :history_offset"
        );
        $this->bindParams($pageStatement, $pageParams);
        $pageStatement->bindValue(':history_limit', $pageSize, PDO::PARAM_INT);
        $pageStatement->bindValue(':history_offset', $offset, PDO::PARAM_INT);
        $pageStatement->execute();

        $rows = [];
        foreach ($pageStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'audit_id' => (int) $row['audit_id'],
                'occurred_at' => (string) $row['occurred_at'],
                'action' => (string) $row['action'],
                'result' => (string) $row['result'],
                'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
                'actor_username' => $row['actor_username'] === null
                    ? null
                    : (string) $row['actor_username'],
                'entity' => (string) $row['entity'],
                'entity_id' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
                'metadata_json' => $row['metadata_json'] === null
                    ? null
                    : (string) $row['metadata_json'],
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'pages' => $total === 0 ? 0 : (int) ceil($total / $pageSize),
        ];
    }

    /** @param array<string, array<string, mixed>> $registry */
    private function assertFilterContract(ProductHistoryFilters $filters, array $registry): void
    {
        if ($filters->action !== null) {
            if (!array_key_exists($filters->action, $registry)
                || !$this->isQueryableEvent($registry[$filters->action])
            ) {
                throw new InvalidArgumentException('Unsupported product history action.');
            }
        }

        if ($filters->subresource !== null) {
            $known = false;
            foreach ($registry as $event) {
                if (($event['subresource'] ?? null) === $filters->subresource) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                throw new InvalidArgumentException('Unsupported product history subresource.');
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function isQueryableEvent(array $event): bool
    {
        $subresource = $event['subresource'] ?? null;
        if ($subresource === null) {
            $entity = array_key_exists('entity', $event) ? $event['entity'] : 'producto';
            $strategy = array_key_exists('product_id_strategy', $event)
                ? $event['product_id_strategy']
                : 'entity_id';
            return $entity === 'producto'
                && in_array($strategy, ['entity_id', null], true);
        }

        return is_string($subresource)
            && is_string($event['entity'] ?? null)
            && ($event['product_id_strategy'] ?? null) === 'metadata.producto_id';
    }

    /** @param array<string, array<string, mixed>> $registry @return list<string> */
    private function directActions(ProductHistoryFilters $filters, array $registry): array
    {
        $actions = [];
        foreach ($registry as $action => $event) {
            if (!$this->isQueryableEvent($event)
                || ($event['subresource'] ?? null) !== null
            ) {
                continue;
            }
            if ($filters->action !== null && $filters->action !== $action) {
                continue;
            }
            if ($filters->subresource !== null) {
                continue;
            }
            $actions[] = $action;
        }
        return $actions;
    }

    /** @param array<string, array<string, mixed>> $registry @return array<string, string> */
    private function subresourceActions(ProductHistoryFilters $filters, array $registry): array
    {
        $actions = [];
        foreach ($registry as $action => $event) {
            $subresource = $event['subresource'] ?? null;
            $entity = $event['entity'] ?? null;
            if (!is_string($subresource) || !is_string($entity)) {
                continue;
            }
            if (($event['product_id_strategy'] ?? null) !== 'metadata.producto_id') {
                continue;
            }
            if ($filters->action !== null && $filters->action !== $action) {
                continue;
            }
            if ($filters->subresource !== null && $filters->subresource !== $subresource) {
                continue;
            }
            $actions[$action] = $entity;
        }
        return $actions;
    }

    /**
     * @param list<string> $actions
     * @param array<string, string> $entitiesByAction
     * @param array<string, scalar> $params
     */
    private function branch(
        string $productId,
        ProductHistoryFilters $filters,
        array $actions,
        array $entitiesByAction,
        string $prefix,
        array &$params
    ): string {
        $where = [];
        $actionPlaceholders = [];
        foreach (array_values($actions) as $index => $action) {
            $name = $prefix . '_action_' . $index;
            $actionPlaceholders[] = ':' . $name;
            $params[$name] = $action;
        }

        $where[] = 'a.accion IN (' . implode(',', $actionPlaceholders) . ')';
        if ($prefix === 'direct') {
        } else {
            $entityConditions = [];
            foreach (array_values($entitiesByAction) as $index => $entity) {
                $actionName = $prefix . '_entity_action_' . $index;
                $entityName = $prefix . '_entity_' . $index;
                $entityConditions[] = '(a.accion = :' . $actionName
                    . ' AND a.entidad = :' . $entityName . ')';
                $params[$actionName] = (string) array_keys($entitiesByAction)[$index];
                $params[$entityName] = $entity;
            }
            $where[] = '(' . implode(' OR ', $entityConditions) . ')';
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')) = :{$prefix}_product_id";
        }
        $productName = $prefix . '_product_id';
        if ($prefix === 'direct') {
            $entityName = $prefix . '_entity';
            $where[] = 'a.entidad = :' . $entityName;
            $params[$entityName] = 'producto';
            $where[] = 'a.entidad_id = :' . $productName;
        }
        $params[$productName] = $productId;

        $this->appendCommonFilters($where, $filters, $prefix, $params);

        return 'SELECT a.id AS audit_id,
                       a.creado_en AS occurred_at,
                       a.accion AS action,
                       a.resultado AS result,
                       a.actor_usuario_id AS actor_id,
                       u.username AS actor_username,
                       a.entidad AS entity,
                       a.entidad_id AS entity_id,
                       a.metadata_json AS metadata_json
                FROM auditoria_eventos a
                LEFT JOIN usuarios u ON u.id = a.actor_usuario_id
                WHERE ' . implode(' AND ', $where);
    }

    /** @param list<string> $where @param array<string, scalar> $params */
    private function appendCommonFilters(array &$where, ProductHistoryFilters $filters, string $prefix, array &$params): void
    {
        if ($filters->actorId !== null) {
            $name = $prefix . '_actor_id';
            $where[] = 'a.actor_usuario_id = :' . $name;
            $params[$name] = $filters->actorId;
        }
        if ($filters->dateFrom !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters->dateFrom);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $filters->dateFrom) {
                throw new InvalidArgumentException('Invalid history start date.');
            }
            $name = $prefix . '_date_from';
            $where[] = 'a.creado_en >= :' . $name;
            $params[$name] = $filters->dateFrom . ' 00:00:00';
        }
        if ($filters->dateTo !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters->dateTo);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $filters->dateTo) {
                throw new InvalidArgumentException('Invalid history end date.');
            }
            $name = $prefix . '_date_to';
            $where[] = 'a.creado_en < :' . $name;
            $params[$name] = $date->modify('+1 day')->format('Y-m-d 00:00:00');
        }
    }

    /** @param array<string, scalar> $params */
    private function bindParams(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }
}

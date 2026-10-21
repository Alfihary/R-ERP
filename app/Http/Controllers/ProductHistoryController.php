<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Products\History\ProductHistoryItem;
use App\Domain\Products\ProductHistoryService;
use App\Domain\Products\ProductNotFoundException;
use InvalidArgumentException;

final class ProductHistoryController
{
    private const ALLOWED_QUERY_KEYS = [
        'id_producto',
        'action',
        'subresource',
        'actor_id',
        'date_from',
        'date_to',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly ProductHistoryService $history
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();

        try {
            $this->assertQueryContract($query);
            $productId = $this->requiredScalar($query, 'id_producto');
            $filters = array_intersect_key(
                $query,
                array_flip([
                    'action',
                    'subresource',
                    'actor_id',
                    'date_from',
                    'date_to',
                ])
            );

            $page = $query['page'] ?? 1;
            $pageSize = $query['page_size'] ?? 25;
            $result = $this->history->page($productId, $filters, $page, $pageSize);

            return Response::json([
                'items' => array_map(
                    static fn (ProductHistoryItem $item): array => self::serializeItem($item),
                    $result->items
                ),
                'total' => $result->total,
                'page' => $result->page,
                'page_size' => $result->pageSize,
                'pages' => $result->pages,
                'filters' => [
                    'subresource' => $result->filters->subresource,
                    'action' => $result->filters->action,
                    'actor_id' => $result->filters->actorId,
                    'date_from' => $this->optionalPublicDate($query, 'date_from'),
                    'date_to' => $this->optionalPublicDate($query, 'date_to'),
                ],
            ]);
        } catch (ProductNotFoundException) {
            return Response::json(['error' => 'product_not_found'], 404);
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'error' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 400);
        }
    }

    /** @param array<string,mixed> $query */
    private function assertQueryContract(array $query): void
    {
        foreach ($query as $key => $value) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_QUERY_KEYS, true)) {
                throw new InvalidArgumentException('Unknown product history parameter.');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException($key . ' must be scalar.');
            }
        }
    }

    /** @param array<string,mixed> $query */
    private function requiredScalar(array $query, string $key): string
    {
        if (!array_key_exists($key, $query)) {
            throw new InvalidArgumentException($key . ' is required.');
        }

        $value = $query[$key];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($key . ' must be scalar.');
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException($key . ' is required.');
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalPublicDate(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) {
            return null;
        }

        return trim((string) $query[$key]);
    }

    /** @return array<string,mixed> */
    private static function serializeItem(ProductHistoryItem $item): array
    {
        return [
            'audit_id' => $item->auditId,
            'occurred_at' => $item->occurredAt,
            'action' => $item->action,
            'category' => $item->category,
            'subresource' => $item->subresource,
            'result' => $item->result,
            'actor_id' => $item->actorId,
            'actor_display' => $item->actorDisplay,
            'product_id' => $item->productId,
            'entity' => $item->entity,
            'entity_id' => $item->entityId,
            'summary' => $item->summary,
            'changed_fields' => $item->changedFields,
            'before' => $item->before,
            'after' => $item->after,
            'metadata' => $item->metadata,
            'completeness' => $item->completeness,
        ];
    }
}

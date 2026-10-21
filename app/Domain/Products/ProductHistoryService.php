<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Products\History\ProductHistoryFilters;
use App\Domain\Products\History\ProductHistoryItem;
use App\Domain\Products\History\ProductHistoryMapper;
use App\Domain\Products\History\ProductHistoryPage;
use App\Infrastructure\Repositories\ProductHistoryQueryRepository;
use App\Infrastructure\Repositories\ProductRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/** Orchestrates product-history reads without SQL or HTTP concerns. */
final class ProductHistoryService
{
    /**
     * @param array<string, array<string, mixed>> $registry
     */
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductHistoryQueryRepository $history,
        private readonly ProductHistoryMapper $mapper,
        private readonly array $registry
    ) {
    }

    public function page(
        string $productId,
        array $rawFilters = [],
        mixed $rawPage = 1,
        mixed $rawPageSize = 25
    ): ProductHistoryPage {
        $productId = trim($productId);
        if ($productId === '') {
            throw new InvalidArgumentException('A product ID is required.');
        }
        if (!$this->products->exists($productId)) {
            throw new ProductNotFoundException('Product not found.');
        }

        $filters = $this->normalizeFilters($rawFilters);
        $page = $this->positiveInt($rawPage, 1);
        $pageSize = min(100, $this->positiveInt($rawPageSize, 25));
        $rawPageSize = $pageSize;

        $rawPage = $page;
        $result = $this->history->paginateForProduct(
            $productId,
            $filters,
            $rawPage,
            $rawPageSize
        );

        $items = [];
        foreach ($result['rows'] as $row) {
            $items[] = $this->mapper->map($row, $productId);
        }

        return new ProductHistoryPage(
            items: $items,
            total: (int) $result['total'],
            page: (int) $result['page'],
            pageSize: (int) $result['page_size'],
            pages: (int) $result['pages'],
            filters: $filters,
        );
    }

    /** @param array<string,mixed> $raw */
    private function normalizeFilters(array $raw): ProductHistoryFilters
    {
        $subresource = $this->optionalText($raw, 'subresource');
        $action = $this->optionalText($raw, 'action');
        $actorId = $this->optionalPositiveInt($raw, 'actor_id');
        $dateFrom = $this->optionalDate($raw, 'date_from');
        $dateTo = $this->optionalDate($raw, 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new InvalidArgumentException('date_from must not be after date_to.');
        }

        if ($subresource !== null) {
            $knownSubresource = false;
            foreach ($this->registry as $event) {
                if (($event['subresource'] ?? null) === $subresource
                    && ($event['product_id_strategy'] ?? null) === 'metadata.producto_id'
                ) {
                    $knownSubresource = true;
                    break;
                }
            }
            if (!$knownSubresource) {
                throw new InvalidArgumentException('Unsupported product history subresource.');
            }
        }

        if ($action !== null) {
            $event = $this->registry[$action] ?? null;
            if (!is_array($event) || !$this->isQueryable($event)) {
                throw new InvalidArgumentException('Unsupported product history action.');
            }
            if ($subresource !== null && ($event['subresource'] ?? null) !== $subresource) {
                throw new InvalidArgumentException('Action and subresource are incompatible.');
            }
        }

        return new ProductHistoryFilters(
            $subresource,
            $action,
            $actorId,
            $this->sqlBoundary($dateFrom, false),
            $this->sqlBoundary($dateTo, true)
        );
    }

    /** @param array<string,mixed> $event */
    private function isQueryable(array $event): bool
    {
        $subresource = $event['subresource'] ?? null;
        if ($subresource === null) {
            return ($event['entity'] ?? 'producto') === 'producto'
                && in_array($event['product_id_strategy'] ?? 'entity_id', ['entity_id', null], true);
        }
        return is_string($subresource)
            && is_string($event['entity'] ?? null)
            && ($event['product_id_strategy'] ?? null) === 'metadata.producto_id';
    }

    /** @param array<string,mixed> $raw */
    private function optionalText(array $raw, string $key): ?string
    {
        if (!array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
            return null;
        }
        if (!is_string($raw[$key]) && !is_int($raw[$key])) {
            throw new InvalidArgumentException($key . ' must be scalar.');
        }
        $value = trim((string) $raw[$key]);
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $raw */
    private function optionalPositiveInt(array $raw, string $key): ?int
    {
        if (!array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
            return null;
        }
        return $this->positiveInt($raw[$key], null, $key);
    }

    private function positiveInt(mixed $value, ?int $default, string $name = 'value'): int
    {
        if ($value === null || $value === '') {
            if ($default !== null) return $default;
            throw new InvalidArgumentException($name . ' is required.');
        }
        if (is_bool($value) || (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1))) {
            throw new InvalidArgumentException($name . ' must be an integer.');
        }
        $number = (int) $value;
        if ($number < 1) {
            throw new InvalidArgumentException($name . ' must be positive.');
        }
        return $number;
    }

    /** @param array<string,mixed> $raw */
    private function optionalDate(array $raw, string $key): ?string
    {
        $value = $this->optionalText($raw, $key);
        if ($value === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($key . ' must use YYYY-MM-DD.');
        }
        return $date->format('Y-m-d');
    }

    private function sqlBoundary(?string $date, bool $exclusiveEnd): ?string
    {
        if ($date === null) {
            return null;
        }

        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Invalid history date.');
        }

        if ($exclusiveEnd) {
            $value = $value->modify('+1 day');
        }

        return $value->format('Y-m-d 00:00:00');
    }
}

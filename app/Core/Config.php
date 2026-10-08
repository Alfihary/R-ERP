<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

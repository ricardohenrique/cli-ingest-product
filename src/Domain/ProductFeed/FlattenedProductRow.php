<?php

declare(strict_types=1);

namespace App\Domain\ProductFeed;

final readonly class FlattenedProductRow
{
    public function __construct(
        private array $data,
        private int $lineNumber,
    ) {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}

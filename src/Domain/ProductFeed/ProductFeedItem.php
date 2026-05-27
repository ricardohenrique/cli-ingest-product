<?php

declare(strict_types=1);

namespace App\Domain\ProductFeed;

final readonly class ProductFeedItem
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
}

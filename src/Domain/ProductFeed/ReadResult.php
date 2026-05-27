<?php

declare(strict_types=1);

namespace App\Domain\ProductFeed;

final readonly class ReadResult
{
    private function __construct(
        private ?ProductFeedItem $item,
        private ?string $error,
        private ?string $rawExcerpt,
        private int $lineNumber,
    ) {
    }

    public static function success(ProductFeedItem $item): self
    {
        return new self($item, null, null, $item->getLineNumber());
    }

    public static function failure(int $lineNumber, string $error, string $rawExcerpt = ''): self
    {
        return new self(null, $error, $rawExcerpt, $lineNumber);
    }

    public function isSuccess(): bool
    {
        return $this->item !== null;
    }

    public function getItem(): ProductFeedItem
    {
        return $this->item;
    }

    public function getError(): string
    {
        return $this->error ?? '';
    }

    public function getRawExcerpt(): string
    {
        return $this->rawExcerpt ?? '';
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }
}

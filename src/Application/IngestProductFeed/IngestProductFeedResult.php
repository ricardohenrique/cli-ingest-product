<?php

declare(strict_types=1);

namespace App\Application\IngestProductFeed;

final class IngestProductFeedResult
{
    private int $processedCount = 0;
    private int $skippedCount = 0;
    /** @var string[] */
    private array $errors = [];

    public function incrementProcessed(): void
    {
        ++$this->processedCount;
    }

    public function incrementSkipped(): void
    {
        ++$this->skippedCount;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

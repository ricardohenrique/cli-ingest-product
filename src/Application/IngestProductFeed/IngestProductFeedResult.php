<?php

declare(strict_types=1);

namespace App\Application\IngestProductFeed;

final readonly class IngestProductFeedResult
{
    public function __construct(
        public int $recordsProcessed,
        public int $recordsSkipped,
        public int $rowsWritten,
        public array $errors,
    ) {}
}

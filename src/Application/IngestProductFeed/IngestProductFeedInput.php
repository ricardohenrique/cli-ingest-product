<?php

declare(strict_types=1);

namespace App\Application\IngestProductFeed;

final readonly class IngestProductFeedInput
{
    public function __construct(
        public string $sourcePath,
    ) {
    }
}

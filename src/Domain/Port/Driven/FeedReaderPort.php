<?php

declare(strict_types=1);

namespace App\Domain\Port\Driven;

use App\Domain\ProductFeed\ReadResult;

interface FeedReaderPort
{
    /** @return iterable<ReadResult> */
    public function read(string $source): iterable;
}

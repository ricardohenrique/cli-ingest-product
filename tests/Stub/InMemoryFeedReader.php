<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\ProductFeed\ReadResult;

final class InMemoryFeedReader implements FeedReaderPort
{
    /** @var ReadResult[] */
    private array $results = [];

    public function addSuccess(array $data, int $line = 1): void
    {
        $this->results[] = ReadResult::success(
            new \App\Domain\ProductFeed\ProductFeedItem($data, $line)
        );
    }

    public function addFailure(int $line, string $error): void
    {
        $this->results[] = ReadResult::failure($line, $error);
    }

    public function read(string $source): iterable
    {
        yield from $this->results;
    }
}

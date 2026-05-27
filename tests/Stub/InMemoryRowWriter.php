<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\FlattenedProductRow;

final class InMemoryRowWriter implements RowWriterPort
{
    /** @var FlattenedProductRow[] */
    private array $writtenRows = [];

    public function write(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->writtenRows[] = $row;
        }
    }

    /** @return FlattenedProductRow[] */
    public function getWrittenRows(): array
    {
        return $this->writtenRows;
    }

    public function countWrittenRows(): int
    {
        return count($this->writtenRows);
    }
}

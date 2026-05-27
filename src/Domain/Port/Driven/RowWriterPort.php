<?php

declare(strict_types=1);

namespace App\Domain\Port\Driven;

use App\Domain\ProductFeed\FlattenedProductRow;

interface RowWriterPort
{
    /** @param iterable<FlattenedProductRow> $rows */
    public function write(iterable $rows): void;
}

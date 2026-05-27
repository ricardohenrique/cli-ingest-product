<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\IngestProductFeed\IngestProductFeedHandler;
use App\Application\IngestProductFeed\IngestProductFeedInput;
use App\Domain\ProductFeed\ProductFlattener;
use App\Domain\ProductFeed\ProductRowValidator;
use App\Tests\Stub\InMemoryFeedReader;
use App\Tests\Stub\InMemoryRowWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IngestProductFeedHandlerTest extends TestCase
{
    private InMemoryFeedReader $reader;
    private InMemoryRowWriter $writer;
    private IngestProductFeedHandler $handler;

    protected function setUp(): void
    {
        $this->reader = new InMemoryFeedReader();
        $this->writer = new InMemoryRowWriter();
        $this->handler = new IngestProductFeedHandler(
            $this->reader,
            $this->writer,
            new ProductFlattener(),
            new ProductRowValidator(),
            new NullLogger(),
        );
    }

    public function testHappyPathThreeValidRecordsAllWritten(): void
    {
        $this->reader->addSuccess($this->makeProduct('BEAN-0001', 2), 1);
        $this->reader->addSuccess($this->makeProduct('BEAN-0002', 1), 2);
        $this->reader->addSuccess($this->makeProduct('BEAN-0003', 3), 3);

        $result = $this->handler->handle(new IngestProductFeedInput('/fake/path.jsonl'));

        // 2 + 1 + 3 = 6 rows written
        self::assertSame(6, $result->getProcessedCount());
        self::assertSame(0, $result->getSkippedCount());
        self::assertSame(6, $this->writer->countWrittenRows());
    }

    public function testMalformedRecordFromReaderIsSkipped(): void
    {
        $this->reader->addSuccess($this->makeProduct('BEAN-0001', 1), 1);
        $this->reader->addFailure(2, 'Syntax error in JSON');
        $this->reader->addSuccess($this->makeProduct('BEAN-0003', 1), 3);

        $result = $this->handler->handle(new IngestProductFeedInput('/fake/path.jsonl'));

        self::assertSame(2, $result->getProcessedCount());
        self::assertSame(1, $result->getSkippedCount());
        self::assertCount(1, $result->getErrors());
        self::assertStringContainsString('line 2', $result->getErrors()[0]);
        self::assertSame(2, $this->writer->countWrittenRows());
    }

    public function testRecordThatFailsValidationIsSkipped(): void
    {
        $this->reader->addSuccess($this->makeProduct('BEAN-0001', 1), 1);
        // record missing top-level 'sku' — validator will reject the flattened row
        $this->reader->addSuccess([
            'name' => 'Bad Bean',
            'variants' => [['sku_variant' => 'V1', 'size' => '100g', 'grind' => 'espresso', 'price_eur' => 5.0, 'stock' => 1]],
        ], 2);
        $this->reader->addSuccess($this->makeProduct('BEAN-0003', 1), 3);

        $result = $this->handler->handle(new IngestProductFeedInput('/fake/path.jsonl'));

        self::assertSame(2, $result->getProcessedCount());
        self::assertSame(1, $result->getSkippedCount());
        self::assertSame(2, $this->writer->countWrittenRows());
    }

    public function testEmptyInputProducesNoWritesAndNoErrors(): void
    {
        $result = $this->handler->handle(new IngestProductFeedInput('/fake/path.jsonl'));

        self::assertSame(0, $result->getProcessedCount());
        self::assertSame(0, $result->getSkippedCount());
        self::assertSame(0, $this->writer->countWrittenRows());
        self::assertEmpty($result->getErrors());
    }

    private function makeProduct(string $sku, int $variantCount): array
    {
        $variants = [];
        for ($i = 0; $i < $variantCount; ++$i) {
            $variants[] = [
                'sku_variant' => $sku.'-V'.$i, // feed field name; flattener normalizes to variant_sku
                'size' => '250g',
                'grind' => 'espresso',
                'price_eur' => 12.0,
                'stock' => 10,
            ];
        }

        return [
            'sku' => $sku,
            'name' => 'Test Bean '.$sku,
            'variants' => $variants,
        ];
    }
}

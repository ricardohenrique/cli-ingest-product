<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Output;

use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\FlattenedProductRow;
use App\Infrastructure\Output\CsvFileRowWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CsvFileRowWriterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_writer_test_');

        if ($tempFile === false) {
            self::fail('Could not create temporary file for test.');
        }

        $this->tempFile = $tempFile;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testThreeRowsProduceCorrectCsvWithHeaderAndDataRows(): void
    {
        $writer = new CsvFileRowWriter($this->tempFile, new NullLogger());

        $writer->write([
            $this->makeRow(['sku' => 'BEAN-001', 'name' => 'Alpha', 'price' => '10.00'], 1),
            $this->makeRow(['sku' => 'BEAN-002', 'name' => 'Beta', 'price' => '12.50'], 2),
            $this->makeRow(['sku' => 'BEAN-003', 'name' => 'Gamma', 'price' => '9.99'], 3),
        ]);

        $lines = $this->readCsvLines($this->tempFile);

        self::assertCount(4, $lines, 'Expected 1 header row + 3 data rows');
        self::assertSame(['sku', 'name', 'price'], $lines[0]);
        self::assertSame(['BEAN-001', 'Alpha', '10.00'], $lines[1]);
        self::assertSame(['BEAN-002', 'Beta', '12.50'], $lines[2]);
        self::assertSame(['BEAN-003', 'Gamma', '9.99'], $lines[3]);
    }

    public function testEmptyInputProducesEmptyFile(): void
    {
        $writer = new CsvFileRowWriter($this->tempFile, new NullLogger());

        $writer->write([]);

        self::assertSame(0, filesize($this->tempFile), 'Expected empty file for empty input');
    }

    public function testUnwritablePathThrowsPersistenceException(): void
    {
        $writer = new CsvFileRowWriter('/nonexistent/path/output.csv', new NullLogger());

        $this->expectException(PersistenceException::class);

        $writer->write([$this->makeRow(['sku' => 'BEAN-001'], 1)]);
    }

    public function testColumnOrderMatchesGetDataKeyOrder(): void
    {
        $writer = new CsvFileRowWriter($this->tempFile, new NullLogger());

        $writer->write([
            $this->makeRow(['z_last' => 'last', 'a_first' => 'first', 'm_middle' => 'middle'], 1),
        ]);

        $lines = $this->readCsvLines($this->tempFile);

        self::assertCount(2, $lines);
        self::assertSame(['z_last', 'a_first', 'm_middle'], $lines[0]);
        self::assertSame(['last', 'first', 'middle'], $lines[1]);
    }

    public function testFieldMapRenamesHeaderKeysButLeavesValuesUnchanged(): void
    {
        $fieldMap = ['sku' => 'product-id', 'name' => 'product-title'];
        $writer = new CsvFileRowWriter($this->tempFile, new NullLogger(), $fieldMap);

        $writer->write([
            $this->makeRow(['sku' => 'BEAN-001', 'name' => 'Alpha', 'price' => '10.00'], 1),
        ]);

        $lines = $this->readCsvLines($this->tempFile);

        self::assertCount(2, $lines);
        self::assertSame(['product-id', 'product-title', 'price'], $lines[0], 'Mapped keys should appear in header');
        self::assertSame(['BEAN-001', 'Alpha', '10.00'], $lines[1], 'Data values should be unchanged');
    }

    public function testPartialFieldMapKeepsUnmappedKeysWithOriginalName(): void
    {
        $fieldMap = ['sku' => 'product-id'];
        $writer = new CsvFileRowWriter($this->tempFile, new NullLogger(), $fieldMap);

        $writer->write([
            $this->makeRow(['sku' => 'BEAN-001', 'name' => 'Alpha', 'price' => '10.00'], 1),
        ]);

        $lines = $this->readCsvLines($this->tempFile);

        self::assertCount(2, $lines);
        self::assertSame(['product-id', 'name', 'price'], $lines[0], 'Only mapped key should be renamed');
        self::assertSame(['BEAN-001', 'Alpha', '10.00'], $lines[1]);
    }

    public function testEmptyFieldMapProducesSameOutputAsNoMap(): void
    {
        $writerNoMap = new CsvFileRowWriter($this->tempFile, new NullLogger());
        $writerNoMap->write([
            $this->makeRow(['sku' => 'BEAN-001', 'name' => 'Alpha'], 1),
        ]);
        $linesNoMap = $this->readCsvLines($this->tempFile);

        $tempFileWithMap = tempnam(sys_get_temp_dir(), 'csv_writer_empty_map_');
        self::assertNotFalse($tempFileWithMap);

        try {
            $writerEmptyMap = new CsvFileRowWriter($tempFileWithMap, new NullLogger(), []);
            $writerEmptyMap->write([
                $this->makeRow(['sku' => 'BEAN-001', 'name' => 'Alpha'], 1),
            ]);
            $linesEmptyMap = $this->readCsvLines($tempFileWithMap);

            self::assertSame($linesNoMap, $linesEmptyMap, 'Empty map must produce identical output to no map');
        } finally {
            if (file_exists($tempFileWithMap)) {
                unlink($tempFileWithMap);
            }
        }
    }

    private function makeRow(array $data, int $lineNumber): FlattenedProductRow
    {
        return new FlattenedProductRow($data, $lineNumber);
    }

    /** @return list<list<string>> */
    private function readCsvLines(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            self::fail(sprintf('Could not open temp file for reading: %s', $path));
        }

        $lines = [];

        try {
            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                $lines[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}

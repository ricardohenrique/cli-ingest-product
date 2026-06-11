<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Console;

use App\Domain\ProductFeed\ProductFlattener;
use App\Domain\ProductFeed\ProductRowValidator;
use App\Infrastructure\Console\IngestProductFeedSymfonyCommand;
use App\Infrastructure\Input\JsonlProductFeedReader;
use App\Tests\Stub\InMemoryRowWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestProductFeedSymfonyCommandTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = \dirname(__DIR__, 3).'/Fixture/valid_feed.jsonl';
    }

    public function testSuccessfulIngestPrintsSummaryAndExitsZero(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['path' => $this->fixturePath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Records processed', $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testMissingPathArgumentExitsOne(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No feed path provided', $tester->getDisplay());
    }

    public function testNonExistentFileExitsOne(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['path' => '/does/not/exist.jsonl']);

        self::assertSame(1, $exitCode);
    }

    public function testWriterCsvWithOutputPathExitsZeroAndWritesFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'feed_csv_test_');
        self::assertNotFalse($tempFile);

        try {
            $tester = $this->buildTester();
            $exitCode = $tester->execute([
                'path' => $this->fixturePath,
                '--writer' => 'csv',
                '--output-path' => $tempFile,
            ]);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('Records processed', $tester->getDisplay());
            self::assertGreaterThan(0, filesize($tempFile), 'CSV output file should not be empty');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testUnknownWriterExitsOne(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            'path' => $this->fixturePath,
            '--writer' => 'bogus',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('bogus', $tester->getDisplay());
        self::assertStringContainsString('Supported: postgres, csv', $tester->getDisplay());
    }

    public function testFieldMapWithCsvWriterRenamesHeaderColumn(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'feed_csv_fieldmap_');
        self::assertNotFalse($tempFile);

        try {
            $tester = $this->buildTester();
            $exitCode = $tester->execute([
                'path' => $this->fixturePath,
                '--writer' => 'csv',
                '--output-path' => $tempFile,
                '--field-map' => 'sku:product-id',
            ]);

            self::assertSame(0, $exitCode);

            $handle = fopen($tempFile, 'r');
            self::assertNotFalse($handle);

            try {
                $headerRow = fgetcsv($handle, escape: '\\');
            } finally {
                fclose($handle);
            }

            self::assertIsArray($headerRow);
            self::assertContains('product-id', $headerRow, 'Renamed header "product-id" must appear in CSV');
            self::assertNotContains('sku', $headerRow, 'Original key "sku" must not appear in CSV header when remapped');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testFieldMapWithoutCsvWriterExitsOne(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            'path' => $this->fixturePath,
            '--writer' => 'postgres',
            '--field-map' => 'sku:product-id',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--field-map is only supported with --writer=csv', $tester->getDisplay());
    }

    public function testMalformedFieldMapPairExitsOne(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            'path' => $this->fixturePath,
            '--writer' => 'csv',
            '--field-map' => 'badformat',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --field-map pair', $tester->getDisplay());
    }

    private function buildTester(): CommandTester
    {
        $doctrineWriter = new InMemoryRowWriter();
        $defaultCsvOutputPath = sys_get_temp_dir().'/feed_test_default_output.csv';

        $command = new IngestProductFeedSymfonyCommand(
            new JsonlProductFeedReader(),
            new ProductFlattener(),
            new ProductRowValidator(),
            new NullLogger(),
            $doctrineWriter,
            $defaultCsvOutputPath,
        );

        return new CommandTester($command);
    }
}

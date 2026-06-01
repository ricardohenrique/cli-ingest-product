<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Console;

use App\Tests\Stub\InMemoryFeedReader;
use App\Tests\Stub\InMemoryRowWriter;
use App\Application\IngestProductFeed\IngestProductFeedHandler;
use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\ProductFlattener;
use App\Domain\ProductFeed\ProductRowValidator;
use App\Infrastructure\Console\IngestProductFeedSymfonyCommand;
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

    private function buildTester(): CommandTester
    {
        $writer = new InMemoryRowWriter();
        $handler = new IngestProductFeedHandler(
            new \App\Infrastructure\Input\JsonlProductFeedReader(),
            $writer,
            new ProductFlattener(),
            new ProductRowValidator(),
            new NullLogger(),
        );

        $command = new IngestProductFeedSymfonyCommand($handler);

        return new CommandTester($command);
    }
}

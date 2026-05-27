<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Input;

use App\Domain\ProductFeed\Exception\FeedSourceException;
use App\Infrastructure\Input\JsonlProductFeedReader;
use PHPUnit\Framework\TestCase;

final class JsonlProductFeedReaderTest extends TestCase
{
    private JsonlProductFeedReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JsonlProductFeedReader();
    }

    public function testValidJsonlYieldsSuccessResults(): void
    {
        $results = iterator_to_array($this->reader->read($this->fixture('valid_feed.jsonl')), false);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertTrue($result->isSuccess());
        }
        self::assertSame('BEAN-0001', $results[0]->getItem()->getData()['sku']);
        self::assertSame('BEAN-0002', $results[1]->getItem()->getData()['sku']);
        self::assertSame('BEAN-0003', $results[2]->getItem()->getData()['sku']);
    }

    public function testLineNumbersAreCorrect(): void
    {
        $results = iterator_to_array($this->reader->read($this->fixture('valid_feed.jsonl')), false);

        self::assertSame(1, $results[0]->getLineNumber());
        self::assertSame(2, $results[1]->getLineNumber());
        self::assertSame(3, $results[2]->getLineNumber());
    }

    public function testMalformedLineYieldsFailureResult(): void
    {
        $results = iterator_to_array($this->reader->read($this->fixture('malformed_feed.jsonl')), false);

        self::assertCount(3, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertFalse($results[1]->isSuccess());
        self::assertSame(2, $results[1]->getLineNumber());
        self::assertNotEmpty($results[1]->getError());
        self::assertNotEmpty($results[1]->getRawExcerpt());
        self::assertTrue($results[2]->isSuccess());
    }

    public function testBlankLinesAreSkipped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'jsonl_test_');
        file_put_contents($file, '{"sku":"A","variants":[]}'. "\n\n" . '{"sku":"B","variants":[]}' . "\n");

        try {
            $results = iterator_to_array($this->reader->read($file), false);
            self::assertCount(2, $results);
            self::assertSame(1, $results[0]->getLineNumber());
            self::assertSame(3, $results[1]->getLineNumber());
        } finally {
            unlink($file);
        }
    }

    public function testNonExistentFileThrowsFeedSourceException(): void
    {
        $this->expectException(FeedSourceException::class);
        iterator_to_array($this->reader->read('/non/existent/path.jsonl'));
    }

    private function fixture(string $name): string
    {
        return \dirname(__DIR__, 3).'/Fixture/'.$name;
    }
}

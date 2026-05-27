<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ProductFeed;

use App\Domain\ProductFeed\Exception\FeedSourceException;
use App\Domain\ProductFeed\Exception\FlatteningException;
use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\Exception\ProductFeedException;
use App\Domain\ProductFeed\FlattenedProductRow;
use App\Domain\ProductFeed\ProductFeedItem;
use App\Domain\ProductFeed\ReadResult;
use PHPUnit\Framework\TestCase;

final class DomainModelTest extends TestCase
{
    public function testProductFeedItemExposesDataAndLineNumber(): void
    {
        $data = ['sku' => 'BEAN-0001', 'name' => 'Test Bean'];
        $item = new ProductFeedItem($data, 42);

        self::assertSame($data, $item->getData());
        self::assertSame(42, $item->getLineNumber());
    }

    public function testFlattenedProductRowExposesDataAndLineNumber(): void
    {
        $data = ['sku' => 'BEAN-0001', 'variant_sku' => 'BEAN-0001-250g-ESP'];
        $row = new FlattenedProductRow($data, 7);

        self::assertSame($data, $row->getData());
        self::assertSame(7, $row->getLineNumber());
    }

    public function testFlattenedProductRowGetAndHas(): void
    {
        $row = new FlattenedProductRow(['sku' => 'BEAN-0001', 'origin_altitude_m' => null], 1);

        self::assertTrue($row->has('sku'));
        self::assertSame('BEAN-0001', $row->get('sku'));
        self::assertTrue($row->has('origin_altitude_m'));
        self::assertNull($row->get('origin_altitude_m'));
        self::assertFalse($row->has('non_existent'));
        self::assertNull($row->get('non_existent'));
    }

    public function testReadResultSuccess(): void
    {
        $item = new ProductFeedItem(['sku' => 'BEAN-0001'], 3);
        $result = ReadResult::success($item);

        self::assertTrue($result->isSuccess());
        self::assertSame($item, $result->getItem());
        self::assertSame(3, $result->getLineNumber());
        self::assertSame('', $result->getError());
    }

    public function testReadResultFailure(): void
    {
        $result = ReadResult::failure(5, 'Syntax error', 'raw line excerpt');

        self::assertFalse($result->isSuccess());
        self::assertSame(5, $result->getLineNumber());
        self::assertSame('Syntax error', $result->getError());
        self::assertSame('raw line excerpt', $result->getRawExcerpt());
    }

    public function testExceptionHierarchy(): void
    {
        self::assertInstanceOf(ProductFeedException::class, new FlatteningException('e'));
        self::assertInstanceOf(ProductFeedException::class, new FeedSourceException('e'));
        self::assertInstanceOf(ProductFeedException::class, new PersistenceException('e'));
        self::assertInstanceOf(\DomainException::class, new FlatteningException('e'));
    }
}

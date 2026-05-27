<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ProductFeed;

use App\Domain\ProductFeed\Exception\FlatteningException;
use App\Domain\ProductFeed\FlattenedProductRow;
use App\Domain\ProductFeed\ProductRowValidator;
use PHPUnit\Framework\TestCase;

final class ProductRowValidatorTest extends TestCase
{
    private ProductRowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProductRowValidator();
    }

    public function testValidRowPassesWithoutException(): void
    {
        $row = new FlattenedProductRow([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'variant_sku' => 'BEAN-0001-250g-ESP',
            'origin_country' => 'Ethiopia',
        ], 1);

        $this->validator->validate($row);
        $this->addToAssertionCount(1);
    }

    public function testRowWithZeroKeysThrows(): void
    {
        $this->expectException(FlatteningException::class);
        $this->validator->validate(new FlattenedProductRow([], 1));
    }

    public function testMissingSkuThrows(): void
    {
        $this->expectException(FlatteningException::class);
        $this->expectExceptionMessage('"sku"');

        $this->validator->validate(new FlattenedProductRow([
            'name' => 'Test Bean',
            'variant_sku' => 'V1',
        ], 1));
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(FlatteningException::class);
        $this->expectExceptionMessage('"name"');

        $this->validator->validate(new FlattenedProductRow([
            'sku' => 'BEAN-0001',
            'variant_sku' => 'V1',
        ], 1));
    }

    public function testMissingVariantSkuThrows(): void
    {
        $this->expectException(FlatteningException::class);
        $this->expectExceptionMessage('"variant_sku"');

        $this->validator->validate(new FlattenedProductRow([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
        ], 1));
    }

    public function testEmptySkuThrows(): void
    {
        $this->expectException(FlatteningException::class);

        $this->validator->validate(new FlattenedProductRow([
            'sku' => '',
            'name' => 'Test Bean',
            'variant_sku' => 'V1',
        ], 1));
    }

    public function testNullRequiredKeyThrows(): void
    {
        $this->expectException(FlatteningException::class);

        $this->validator->validate(new FlattenedProductRow([
            'sku' => null,
            'name' => 'Test Bean',
            'variant_sku' => 'V1',
        ], 1));
    }
}

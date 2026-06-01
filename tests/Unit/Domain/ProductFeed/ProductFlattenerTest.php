<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ProductFeed;

use App\Domain\ProductFeed\Exception\FlatteningException;
use App\Domain\ProductFeed\ProductFeedItem;
use App\Domain\ProductFeed\ProductFlattener;
use PHPUnit\Framework\TestCase;

final class ProductFlattenerTest extends TestCase
{
    private ProductFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new ProductFlattener();
    }

    public function testSingleVariantProducesOneRow(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'variants' => [
                ['sku_variant' => 'BEAN-0001-250g-ESP', 'size' => '250g', 'grind' => 'espresso', 'price_eur' => 12.5, 'stock' => 10],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertCount(1, $rows);
        self::assertSame('BEAN-0001', $rows[0]->get('sku'));
        self::assertSame('BEAN-0001-250g-ESP', $rows[0]->get('variant_sku'));
        self::assertSame(0, $rows[0]->get('variant_index'));
    }

    public function testThreeVariantsProduceThreeRows(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '100g', 'grind' => 'espresso', 'price_eur' => 5.0, 'stock' => 1],
                ['sku_variant' => 'V2', 'size' => '250g', 'grind' => 'filter', 'price_eur' => 10.0, 'stock' => 2],
                ['sku_variant' => 'V3', 'size' => '500g', 'grind' => 'moka', 'price_eur' => 18.0, 'stock' => 3],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertCount(3, $rows);
        self::assertSame(0, $rows[0]->get('variant_index'));
        self::assertSame(1, $rows[1]->get('variant_index'));
        self::assertSame(2, $rows[2]->get('variant_index'));
        self::assertSame('V1', $rows[0]->get('variant_sku'));
        self::assertSame('V2', $rows[1]->get('variant_sku'));
        self::assertSame('V3', $rows[2]->get('variant_sku'));
    }

    public function testProductLevelFieldsRepeatedOnEveryVariantRow(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'in_stock' => true,
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '100g', 'grind' => 'espresso', 'price_eur' => 5.0, 'stock' => 1],
                ['sku_variant' => 'V2', 'size' => '250g', 'grind' => 'filter', 'price_eur' => 10.0, 'stock' => 2],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertSame('BEAN-0001', $rows[0]->get('sku'));
        self::assertSame('BEAN-0001', $rows[1]->get('sku'));
        self::assertSame('Test Bean', $rows[0]->get('name'));
        self::assertTrue($rows[0]->get('in_stock'));
    }

    public function testNestedObjectFlattensToDotNotationKeys(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'origin' => [
                'country' => 'Ethiopia',
                'region' => 'Sidamo',
                'farm' => 'Test Farm',
                'altitude_m' => 1600,
                'process' => 'washed',
            ],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '250g', 'grind' => 'espresso', 'price_eur' => 12.0, 'stock' => 5],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertSame('Ethiopia', $rows[0]->get('origin_country'));
        self::assertSame('Sidamo', $rows[0]->get('origin_region'));
        self::assertSame(1600, $rows[0]->get('origin_altitude_m'));
        self::assertFalse($rows[0]->has('origin'));
    }

    public function testOptionalCoordinatesPresentWhenInRecord(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'origin' => [
                'country' => 'Rwanda',
                'region' => 'Nyamasheke',
                'farm' => 'Farm',
                'altitude_m' => 1848,
                'process' => 'natural',
                'coordinates' => ['lat' => 33.3024, 'lng' => -4.3151],
            ],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '1kg', 'grind' => 'filter', 'price_eur' => 47.0, 'stock' => 10],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertSame(33.3024, $rows[0]->get('origin_coordinates_lat'));
        self::assertSame(-4.3151, $rows[0]->get('origin_coordinates_lng'));
    }

    public function testCoordinatesAbsentWhenNotInRecord(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'origin' => [
                'country' => 'Ethiopia',
                'region' => 'Sidamo',
                'farm' => 'Farm',
                'altitude_m' => 1600,
                'process' => 'washed',
            ],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '250g', 'grind' => 'espresso', 'price_eur' => 12.0, 'stock' => 5],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertFalse($rows[0]->has('origin_coordinates_lat'));
        self::assertFalse($rows[0]->has('origin_coordinates_lng'));
    }

    public function testNullAltitudePreservedInRow(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0005',
            'name' => 'Boss Battle Brew',
            'origin' => ['country' => 'Avalon', 'region' => 'Apple Isle', 'farm' => 'Farm', 'altitude_m' => null, 'process' => 'washed'],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '500g', 'grind' => 'espresso', 'price_eur' => 22.0, 'stock' => 5],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertTrue($rows[0]->has('origin_altitude_m'));
        self::assertNull($rows[0]->get('origin_altitude_m'));
    }

    public function testFlavorNotesSerializedAsCommaSeparatedString(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0002',
            'name' => 'Test',
            'flavor_notes' => ['milk chocolate', 'regret'],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '250g', 'grind' => 'turkish', 'price_eur' => 13.0, 'stock' => 20],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertSame('milk chocolate,regret', $rows[0]->get('flavor_notes'));
    }

    public function testEmptyFlavorNotesBecomesEmptyString(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test',
            'flavor_notes' => [],
            'variants' => [
                ['sku_variant' => 'V1', 'size' => '250g', 'grind' => 'espresso', 'price_eur' => 12.0, 'stock' => 5],
            ],
        ]);

        $rows = $this->flattener->flatten($item);

        self::assertSame('', $rows[0]->get('flavor_notes'));
    }

    public function testZeroVariantsThrowsFlatteningException(): void
    {
        $item = $this->makeItem([
            'sku' => 'BEAN-0001',
            'name' => 'Test Bean',
            'variants' => [],
        ]);

        $this->expectException(FlatteningException::class);

        $this->flattener->flatten($item);
    }

    private function makeItem(array $data, int $line = 1): ProductFeedItem
    {
        return new ProductFeedItem($data, $line);
    }
}

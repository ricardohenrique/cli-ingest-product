<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\FlattenedProductRow;
use App\Infrastructure\Persistence\DoctrineFlattenedProductWriter;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DoctrineFlattenedProductWriterTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private DoctrineFlattenedProductWriter $writer;

    protected function setUp(): void
    {
        $url = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: null;

        if (!$url) {
            self::markTestSkipped('DATABASE_URL not set — skipping DB integration tests.');
        }

        $params = (new \Doctrine\DBAL\Tools\DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres'   => 'pdo_pgsql',
        ]))->parse($url);

        try {
            $this->connection = DriverManager::getConnection($params);
        } catch (\Throwable) {
            self::markTestSkipped('Database unavailable — skipping DB integration tests.');
        }
        $this->connection->executeStatement('TRUNCATE TABLE flattened_products RESTART IDENTITY');

        $this->writer = new DoctrineFlattenedProductWriter($this->connection, new NullLogger(), 500);
    }

    protected function tearDown(): void
    {
        // Restore the schema if a test dropped the table (e.g. testWriterThrowsPersistenceExceptionOnDbFailure)
        $tables = $this->connection->createSchemaManager()->listTableNames();
        if (!in_array('flattened_products', $tables, true)) {
            $this->connection->executeStatement(<<<'SQL'
                CREATE TABLE flattened_products (
                    id                          bigserial       PRIMARY KEY,
                    sku                         varchar(64)     NOT NULL,
                    name                        varchar(255)    NOT NULL,
                    origin_country              varchar(128)    NOT NULL,
                    origin_region               varchar(128)    NOT NULL,
                    origin_farm                 varchar(128)    NOT NULL,
                    origin_altitude_m           int             NULL,
                    origin_process              varchar(64)     NOT NULL,
                    origin_coordinates_lat      numeric(9,6)    NULL,
                    origin_coordinates_lng      numeric(9,6)    NULL,
                    roast_level                 varchar(32)     NOT NULL,
                    roast_roasted_on            date            NOT NULL,
                    roast_roaster               varchar(128)    NOT NULL,
                    flavor_notes                text            NOT NULL DEFAULT '',
                    tags                        text            NOT NULL DEFAULT '',
                    tasting_score_acidity       smallint        NOT NULL,
                    tasting_score_body          smallint        NOT NULL,
                    tasting_score_sweetness     smallint        NOT NULL,
                    tasting_score_aroma         smallint        NOT NULL,
                    tasting_score_bitterness    smallint        NOT NULL,
                    in_stock                    boolean         NOT NULL,
                    description                 text            NULL,
                    variant_sku                 varchar(128)    NOT NULL,
                    variant_size                varchar(16)     NOT NULL,
                    variant_grind               varchar(32)     NOT NULL,
                    variant_price_eur           numeric(10,2)   NOT NULL,
                    variant_stock               int             NOT NULL,
                    variant_index               smallint        NOT NULL
                )
            SQL);
            $this->connection->executeStatement(
                'ALTER TABLE flattened_products ADD CONSTRAINT uq_flattened_products_sku_variant UNIQUE (sku, variant_sku)',
            );
        }

        $this->connection?->close();
    }

    public function testThreeRowsAreWrittenToDatabase(): void
    {
        $rows = [
            $this->makeRow('BEAN-0001', 'V1', 0),
            $this->makeRow('BEAN-0002', 'V2', 0),
            $this->makeRow('BEAN-0003', 'V3', 0),
        ];

        $this->writer->write($rows);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('3', (string) $count);
    }

    public function testEmptyInputWritesNoRows(): void
    {
        $this->writer->write([]);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('0', (string) $count);
    }

    public function testChunkingBoundaryWith1200Rows(): void
    {
        $rows = [];
        for ($i = 0; $i < 1200; ++$i) {
            $rows[] = $this->makeRow('BEAN-'.str_pad((string) $i, 4, '0', \STR_PAD_LEFT), 'V'.$i, 0);
        }

        $this->writer->write($rows);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('1200', (string) $count);
    }

    public function testWriterThrowsPersistenceExceptionOnDbFailure(): void
    {
        // Drop the table to simulate a DB failure mid-write
        $this->connection->executeStatement('DROP TABLE flattened_products');

        $this->expectException(PersistenceException::class);

        $this->writer->write([$this->makeRow('BEAN-0001', 'V1', 0)]);
    }

    public function testWritingSameRowTwiceProducesSingleRow(): void
    {
        $this->writer->write([$this->makeRow('BEAN-0001', 'V1', 0, variantStock: 10)]);
        $this->writer->write([$this->makeRow('BEAN-0001', 'V1', 0, variantStock: 99)]);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('1', (string) $count);

        $stock = $this->connection->fetchOne(
            'SELECT variant_stock FROM flattened_products WHERE sku = :sku AND variant_sku = :variant_sku',
            ['sku' => 'BEAN-0001', 'variant_sku' => 'V1'],
        );
        self::assertSame('99', (string) $stock);
    }

    public function testWritingMixOfNewAndExistingRowsUpsertsCorrectly(): void
    {
        $this->writer->write([
            $this->makeRow('BEAN-0001', 'V1', 0, variantStock: 10),
            $this->makeRow('BEAN-0002', 'V1', 0, variantStock: 20),
        ]);

        $this->writer->write([
            $this->makeRow('BEAN-0001', 'V1', 0, variantStock: 55),
            $this->makeRow('BEAN-0003', 'V1', 0, variantStock: 30),
        ]);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('3', (string) $count);

        $bean0001Stock = $this->connection->fetchOne(
            'SELECT variant_stock FROM flattened_products WHERE sku = :sku AND variant_sku = :variant_sku',
            ['sku' => 'BEAN-0001', 'variant_sku' => 'V1'],
        );
        self::assertSame('55', (string) $bean0001Stock);

        $bean0002Stock = $this->connection->fetchOne(
            'SELECT variant_stock FROM flattened_products WHERE sku = :sku AND variant_sku = :variant_sku',
            ['sku' => 'BEAN-0002', 'variant_sku' => 'V1'],
        );
        self::assertSame('20', (string) $bean0002Stock);
    }

    public function testDifferentSkusWithSameVariantSkuAreTreatedAsDistinct(): void
    {
        $this->writer->write([
            $this->makeRow('BEAN-0001', 'V1', 0),
            $this->makeRow('BEAN-0002', 'V1', 0),
        ]);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('2', (string) $count);
    }

    public function testUpsertWithinSingleChunkBatch(): void
    {
        $rows = [
            $this->makeRow('BEAN-0001', 'V1', 0, variantStock: 10),
            $this->makeRow('BEAN-0001', 'V1', 0, variantStock: 77),
        ];

        $this->writer->write($rows);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM flattened_products');
        self::assertSame('1', (string) $count);

        $stock = $this->connection->fetchOne(
            'SELECT variant_stock FROM flattened_products WHERE sku = :sku AND variant_sku = :variant_sku',
            ['sku' => 'BEAN-0001', 'variant_sku' => 'V1'],
        );
        self::assertSame('77', (string) $stock);
    }

    private function makeRow(string $sku, string $variantSku, int $index, int $variantStock = 10): FlattenedProductRow
    {
        return new FlattenedProductRow([
            'sku' => $sku,
            'name' => 'Test Bean '.$sku,
            'origin_country' => 'Ethiopia',
            'origin_region' => 'Sidamo',
            'origin_farm' => 'Test Farm',
            'origin_altitude_m' => 1600,
            'origin_process' => 'washed',
            'roast_level' => 'medium',
            'roast_roasted_on' => '2026-01-01',
            'roast_roaster' => 'Test Roastery',
            'flavor_notes' => 'chocolate,caramel',
            'tags' => 'organic',
            'tasting_score_acidity' => 5,
            'tasting_score_body' => 7,
            'tasting_score_sweetness' => 6,
            'tasting_score_aroma' => 8,
            'tasting_score_bitterness' => 4,
            'in_stock' => true,
            'description' => null,
            'variant_sku' => $variantSku,
            'variant_size' => '250g',
            'variant_grind' => 'espresso',
            'variant_price_eur' => 12.50,
            'variant_stock' => $variantStock,
            'variant_index' => $index,
        ], 1);
    }
}

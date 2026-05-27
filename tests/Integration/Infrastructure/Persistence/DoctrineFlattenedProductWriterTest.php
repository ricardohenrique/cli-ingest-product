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
            $this->connection->connect();
        } catch (\Throwable) {
            self::markTestSkipped('Database unavailable — skipping DB integration tests.');
        }
        $this->connection->executeStatement('TRUNCATE TABLE flattened_products RESTART IDENTITY');

        $this->writer = new DoctrineFlattenedProductWriter($this->connection, new NullLogger(), 500);
    }

    protected function tearDown(): void
    {
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

    private function makeRow(string $sku, string $variantSku, int $index): FlattenedProductRow
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
            'variant_stock' => 10,
            'variant_index' => $index,
        ], 1);
    }
}

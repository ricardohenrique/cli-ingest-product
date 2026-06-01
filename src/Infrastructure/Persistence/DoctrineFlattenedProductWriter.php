<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\FlattenedProductRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

final class DoctrineFlattenedProductWriter implements RowWriterPort
{
    private const TABLE = 'flattened_products';
    private const DEFAULT_CHUNK_SIZE = 500;

    /** @var list<string> */
    private const KEY_COLUMNS = ['sku', 'variant_sku'];

    private const COLUMNS = [
        'sku', 'name',
        'origin_country', 'origin_region', 'origin_farm',
        'origin_altitude_m', 'origin_process',
        'origin_coordinates_lat', 'origin_coordinates_lng',
        'roast_level', 'roast_roasted_on', 'roast_roaster',
        'flavor_notes', 'tags',
        'tasting_score_acidity', 'tasting_score_body', 'tasting_score_sweetness',
        'tasting_score_aroma', 'tasting_score_bitterness',
        'in_stock', 'description',
        'variant_sku', 'variant_size', 'variant_grind',
        'variant_price_eur', 'variant_stock', 'variant_index',
    ];

    // Explicit DBAL types for non-string columns. Without these, DBAL defaults
    // to PDO::PARAM_STR, which casts PHP false → "" and breaks boolean columns.
    private const COLUMN_TYPES = [
        'origin_altitude_m'       => Types::INTEGER,
        'origin_coordinates_lat'  => Types::FLOAT,
        'origin_coordinates_lng'  => Types::FLOAT,
        'tasting_score_acidity'   => Types::INTEGER,
        'tasting_score_body'      => Types::INTEGER,
        'tasting_score_sweetness' => Types::INTEGER,
        'tasting_score_aroma'     => Types::INTEGER,
        'tasting_score_bitterness'=> Types::INTEGER,
        'in_stock'                => Types::BOOLEAN,
        'variant_price_eur'       => Types::FLOAT,
        'variant_stock'           => Types::INTEGER,
        'variant_index'           => Types::INTEGER,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
    }

    public function write(iterable $rows): void
    {
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = $row;

            if (count($chunk) >= $this->chunkSize) {
                $this->insertChunk($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $this->insertChunk($chunk);
        }
    }

    /** @param FlattenedProductRow[] $rows */
    private function insertChunk(array $rows): void
    {
        $this->connection->beginTransaction();

        try {
            // Group rows by their column-signature so each batch statement
            // has an identical column list (rows may omit optional columns).
            $groups = $this->groupByColumnSignature($rows);

            foreach ($groups as $group) {
                [$columns, $flatValues, $flatTypes] = $this->buildBatchParams($group);
                $sql = $this->buildBatchUpsertSql($columns, count($group));
                $this->connection->executeStatement($sql, $flatValues, $flatTypes);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('Failed to write batch to database', [
                'rows' => count($rows),
                'error' => $e->getMessage(),
            ]);
            throw new PersistenceException(
                sprintf('Failed to write batch of %d rows: %s', count($rows), $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Groups rows by the sorted list of column keys present in each row.
     * Within each group, rows are de-duplicated by (sku, variant_sku) keeping
     * the last occurrence so the batch INSERT does not violate the unique
     * constraint when the same key appears twice in a single chunk.
     *
     * @param  FlattenedProductRow[] $rows
     * @return array<string, FlattenedProductRow[]>
     */
    private function groupByColumnSignature(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            [$data] = $this->mapToColumns($row);
            $signature = implode(',', array_keys($data));

            // De-duplicate within group by conflict-key so a single batch
            // INSERT does not attempt to update the same row twice.
            $conflictKey = ($data['sku'] ?? '')."\x00".($data['variant_sku'] ?? '');
            $groups[$signature][$conflictKey] = $row;
        }

        // Strip the inner conflict-key index before returning
        return array_map('array_values', $groups);
    }

    /**
     * Builds the flat values and types arrays for a batch of same-shape rows.
     * Only columns with explicit DBAL types appear in $flatTypes; DBAL defaults
     * untyped positional parameters to ParameterType::STRING automatically.
     *
     * @param  FlattenedProductRow[] $rows
     * @return array{0: list<string>, 1: list<mixed>, 2: array<int, string>}
     */
    private function buildBatchParams(array $rows): array
    {
        $flatValues = [];
        $flatTypes = [];
        $columns = null;
        $positionalIndex = 0;

        foreach ($rows as $row) {
            [$data, $types] = $this->mapToColumns($row);

            if ($columns === null) {
                $columns = array_keys($data);
            }

            foreach ($data as $column => $value) {
                $flatValues[] = $value;
                if (isset($types[$column])) {
                    $flatTypes[$positionalIndex] = $types[$column];
                }
                ++$positionalIndex;
            }
        }

        return [$columns ?? [], $flatValues, $flatTypes];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function mapToColumns(FlattenedProductRow $row): array
    {
        $data = [];
        $types = [];

        foreach (self::COLUMNS as $column) {
            if (!$row->has($column)) {
                continue;
            }
            $data[$column] = $row->get($column);

            if (isset(self::COLUMN_TYPES[$column])) {
                $types[$column] = self::COLUMN_TYPES[$column];
            }
        }

        return [$data, $types];
    }

    /**
     * Builds a multi-row upsert SQL statement using positional ? placeholders.
     *
     * @param list<string> $columns
     */
    private function buildBatchUpsertSql(array $columns, int $rowCount): string
    {
        $columnList = implode(', ', $columns);

        $singleRowPlaceholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $allRowPlaceholders = implode(', ', array_fill(0, $rowCount, $singleRowPlaceholders));

        $updateColumns = array_filter(
            $columns,
            static fn (string $c) => !in_array($c, self::KEY_COLUMNS, true),
        );

        $setClauses = implode(
            ', ',
            array_map(static fn (string $c) => $c.' = EXCLUDED.'.$c, $updateColumns),
        );

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
            self::TABLE,
            $columnList,
            $allRowPlaceholders,
            implode(', ', self::KEY_COLUMNS),
            $setClauses,
        );
    }
}

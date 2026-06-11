<?php

declare(strict_types=1);

namespace App\Infrastructure\Output;

use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\FlattenedProductRow;
use Psr\Log\LoggerInterface;

final class CsvFileRowWriter implements RowWriterPort
{
    /** @param array<string, string> $fieldMap */
    public function __construct(
        private readonly string $outputPath,
        private readonly LoggerInterface $logger,
        private readonly array $fieldMap = [],
    ) {
    }

    /** @param iterable<FlattenedProductRow> $rows */
    public function write(iterable $rows): void
    {
        $handle = @fopen($this->outputPath, 'w');

        if ($handle === false) {
            throw new PersistenceException(
                sprintf('Failed to open CSV output file for writing: %s', $this->outputPath),
            );
        }

        try {
            $headerWritten = false;

            foreach ($rows as $row) {
                $data = $row->getData();

                if (!$headerWritten) {
                    $keys = array_keys($data);
                    $headers = array_map(fn(string $k) => $this->fieldMap[$k] ?? $k, $keys);
                    fputcsv($handle, $headers, escape: '\\');
                    $headerWritten = true;
                }

                fputcsv($handle, array_values($data), escape: '\\');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write row to CSV file', [
                'path' => $this->outputPath,
                'error' => $e->getMessage(),
            ]);
            throw new PersistenceException(
                sprintf('Failed to write to CSV file "%s": %s', $this->outputPath, $e->getMessage()),
                0,
                $e,
            );
        } finally {
            fclose($handle);
        }
    }
}

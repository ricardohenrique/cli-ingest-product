<?php

declare(strict_types=1);

namespace App\Infrastructure\Output;

use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\PersistenceException;
use App\Domain\ProductFeed\FlattenedProductRow;
use Psr\Log\LoggerInterface;

final class CsvFileRowWriter implements RowWriterPort
{
    public function __construct(
        private readonly string $outputPath,
        private readonly LoggerInterface $logger,
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
                    fputcsv($handle, array_keys($data), escape: '\\');
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

<?php

declare(strict_types=1);

namespace App\Application\IngestProductFeed;

use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\ProductFeedException;
use App\Domain\ProductFeed\ProductFlattener;
use App\Domain\ProductFeed\ProductRowValidator;
use Psr\Log\LoggerInterface;

final class IngestProductFeedHandler
{
    public function __construct(
        private readonly FeedReaderPort $reader,
        private readonly RowWriterPort $writer,
        private readonly ProductFlattener $flattener,
        private readonly ProductRowValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(IngestProductFeedInput $input): IngestProductFeedResult
    {
        $recordsProcessed = 0;
        $recordsSkipped = 0;
        $rowsWritten = 0;
        $errors = [];

        $this->writer->write(
            $this->generateRows($input, $recordsProcessed, $recordsSkipped, $rowsWritten, $errors)
        );

        return new IngestProductFeedResult($recordsProcessed, $recordsSkipped, $rowsWritten, $errors);
    }

    private function generateRows(
        IngestProductFeedInput $input,
        int &$recordsProcessed,
        int &$recordsSkipped,
        int &$rowsWritten,
        array &$errors,
    ): \Generator {
        foreach ($this->reader->read($input->sourcePath) as $readResult) {
            if (!$readResult->isSuccess()) {
                $this->logger->warning('Failed to read record', [
                    'line' => $readResult->getLineNumber(),
                    'source' => $input->sourcePath,
                    'error' => $readResult->getError(),
                    'excerpt' => $readResult->getRawExcerpt(),
                ]);
                ++$recordsSkipped;
                $errors[] = sprintf('line %d: %s', $readResult->getLineNumber(), $readResult->getError());
                continue;
            }

            $item = $readResult->getItem();

            try {
                $flattenedRows = $this->flattener->flatten($item);

                foreach ($flattenedRows as $row) {
                    $this->validator->validate($row);
                    ++$rowsWritten;
                    yield $row;
                }
                ++$recordsProcessed;
            } catch (ProductFeedException $e) {
                $this->logger->warning('Failed to process record', [
                    'line' => $item->getLineNumber(),
                    'source' => $input->sourcePath,
                    'error' => $e->getMessage(),
                ]);
                ++$recordsSkipped;
                $errors[] = sprintf('line %d: %s', $item->getLineNumber(), $e->getMessage());
            }
        }
    }
}

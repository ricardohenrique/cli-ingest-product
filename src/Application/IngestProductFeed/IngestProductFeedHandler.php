<?php

declare(strict_types=1);

namespace App\Application\IngestProductFeed;

use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\FlatteningException;
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
        $result = new IngestProductFeedResult();

        $this->writer->write($this->generateRows($input, $result));

        return $result;
    }

    private function generateRows(IngestProductFeedInput $input, IngestProductFeedResult $result): \Generator
    {
        foreach ($this->reader->read($input->sourcePath) as $readResult) {
            if (!$readResult->isSuccess()) {
                $this->logger->warning('Failed to read record', [
                    'line' => $readResult->getLineNumber(),
                    'source' => $input->sourcePath,
                    'error' => $readResult->getError(),
                    'excerpt' => $readResult->getRawExcerpt(),
                ]);
                $result->incrementSkipped();
                $result->addError(sprintf('line %d: %s', $readResult->getLineNumber(), $readResult->getError()));
                continue;
            }

            $item = $readResult->getItem();

            try {
                $flattenedRows = $this->flattener->flatten($item);

                foreach ($flattenedRows as $row) {
                    $this->validator->validate($row);
                    $result->incrementProcessed();
                    yield $row;
                }
            } catch (FlatteningException $e) {
                $this->logger->warning('Failed to process record', [
                    'line' => $item->getLineNumber(),
                    'source' => $input->sourcePath,
                    'error' => $e->getMessage(),
                ]);
                $result->incrementSkipped();
                $result->addError(sprintf('line %d: %s', $item->getLineNumber(), $e->getMessage()));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Input;

use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\ProductFeed\Exception\FeedSourceException;
use App\Domain\ProductFeed\ProductFeedItem;
use App\Domain\ProductFeed\ReadResult;

final class JsonlProductFeedReader implements FeedReaderPort
{
    private const EXCERPT_LENGTH = 200;

    public function read(string $source): iterable
    {
        $handle = @fopen($source, 'r');
        if ($handle === false) {
            throw new FeedSourceException(sprintf('Cannot open feed source: %s', $source));
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber;
                $trimmed = trim($line);

                if ($trimmed === '') {
                    continue;
                }

                $data = json_decode($trimmed, true);
                if (json_last_error() !== \JSON_ERROR_NONE) {
                    yield ReadResult::failure(
                        $lineNumber,
                        json_last_error_msg(),
                        substr($trimmed, 0, self::EXCERPT_LENGTH),
                    );
                    continue;
                }

                yield ReadResult::success(new ProductFeedItem($data, $lineNumber));
            }
        } finally {
            fclose($handle);
        }
    }
}

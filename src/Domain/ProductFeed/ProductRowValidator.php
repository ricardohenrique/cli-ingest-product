<?php

declare(strict_types=1);

namespace App\Domain\ProductFeed;

use App\Domain\ProductFeed\Exception\FlatteningException;

final class ProductRowValidator
{
    private const REQUIRED_KEYS = ['sku', 'name', 'variant_sku'];

    public function validate(FlattenedProductRow $row): void
    {
        if (empty($row->getData())) {
            throw new FlatteningException('Row has no data');
        }

        foreach (array_keys($row->getData()) as $key) {
            if (trim((string) $key) === '') {
                throw new FlatteningException('Row contains an empty or whitespace-only key');
            }
        }

        foreach (self::REQUIRED_KEYS as $required) {
            if (!$row->has($required)) {
                throw new FlatteningException(sprintf('Missing required key "%s"', $required));
            }

            $value = $row->get($required);
            if ($value === null || $value === '') {
                throw new FlatteningException(sprintf('Required key "%s" is null or empty', $required));
            }
        }
    }
}

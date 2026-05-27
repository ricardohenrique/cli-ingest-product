<?php

declare(strict_types=1);

namespace App\Domain\ProductFeed;

final class ProductFlattener
{
    /**
     * @return FlattenedProductRow[]
     */
    public function flatten(ProductFeedItem $item): array
    {
        $data = $item->getData();
        $lineNumber = $item->getLineNumber();

        $variants = $data['variants'] ?? [];
        unset($data['variants']);

        $productData = $this->flattenValue($data, '');

        if (empty($variants)) {
            return [];
        }

        $rows = [];
        foreach ($variants as $index => $variant) {
            $variantData = [];
            foreach ($variant as $key => $value) {
                // Normalize the feed's 'sku_variant' field to 'sku' so the
                // column lands as 'variant_sku', matching the output schema.
                $normalizedKey = $key === 'sku_variant' ? 'sku' : $key;
                $variantData['variant_'.$normalizedKey] = $value;
            }
            $variantData['variant_index'] = $index;

            $rows[] = new FlattenedProductRow(array_merge($productData, $variantData), $lineNumber);
        }

        return $rows;
    }

    private function flattenValue(mixed $value, string $prefix): array
    {
        if (is_array($value)) {
            if ($this->isAssociative($value)) {
                return $this->flattenAssociative($value, $prefix);
            }

            // Scalar (string) array → comma-joined; absent optional objects → skip key entirely
            return [$prefix => implode(',', array_map('strval', $value))];
        }

        return [$prefix => $value];
    }

    private function flattenAssociative(array $assoc, string $prefix): array
    {
        $result = [];
        foreach ($assoc as $key => $value) {
            $flatKey = $prefix !== '' ? $prefix.'_'.$key : $key;

            if (is_array($value) && $this->isAssociative($value)) {
                $result = array_merge($result, $this->flattenAssociative($value, $flatKey));
            } elseif (is_array($value)) {
                // Scalar array → serialize
                $result[$flatKey] = implode(',', array_map('strval', $value));
            } else {
                $result[$flatKey] = $value;
            }
        }

        return $result;
    }

    private function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}

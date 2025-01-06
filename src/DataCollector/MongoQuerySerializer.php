<?php

declare(strict_types=1);

namespace Facile\MongoDbBundle\DataCollector;

use Facile\MongoDbBundle\Models\Query;
use MongoDB\BSON\Serializable;

/**
 * @internal
 */
final class MongoQuerySerializer
{
    public static function serialize(Query $query): void
    {
        $query->setFilters(self::prepareUnserializableData($query->getFilters()));
        $query->setData(self::prepareUnserializableData($query->getData()));
        $query->setOptions(self::prepareUnserializableData($query->getOptions()));
    }

    /**
     * @param array|object $data
     *
     * @return mixed[]
     */
    private static function prepareUnserializableData($data, int $maxDepth = 50): array
    {
        if ($data instanceof Serializable) {
            $data = $data->bsonSerialize();
        }

        $newData = [];
        foreach ($data as $key => $item) {
            $newData[$key] = self::prepareItemData($item, $maxDepth - 1);
        }

        return $newData;
    }

    /**
     * @param mixed $item
     *
     * @return mixed
     */
    public static function prepareItemData($item, int $maxDepth = 50)
    {
        // Prevent infinite recursion
        if ($maxDepth < 0) {
            return null;
        }

        if (\is_scalar($item)) {
            return $item;
        }

        if (\is_array($item)) {
            return self::prepareUnserializableData($item, $maxDepth);
        }

        if (\is_object($item)) {
            if (method_exists($item, 'getArrayCopy')) {
                return self::prepareUnserializableData($item->getArrayCopy(), $maxDepth);
            }

            if (method_exists($item, 'toDateTime')) {
                return 'ISODate("' . $item->toDateTime()->format('c') . '")';
            }

            if (method_exists($item, '__toString')) {
                return $item->__toString();
            }

            if ($item instanceof Serializable) {
                return $item->bsonSerialize();
            }

            return self::prepareUnserializableData((array) $item, $maxDepth);
        }

        return $item;
    }
}

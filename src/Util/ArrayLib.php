<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Util;

final class ArrayLib
{
    /**
     * Recursively merges two arrays. The right-most array's value takes precedence.
     *
     * Behaves similar to array_merge_recursive(), however it only merges
     * values when both are arrays rather than creating a new array with
     * both values, as the PHP version does. It also treats all arrays as associative.
     */
    public static function arrayMergeRecursive(array $array1, array ...$arrays): array
    {
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && array_key_exists($key, $array1) && is_array($array1[$key])) {
                    $array1[$key] = self::arrayMergeRecursive($array1[$key], $value);
                } else {
                    $array1[$key] = $value;
                }
            }
        }
        return $array1;
    }
}

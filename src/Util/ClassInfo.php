<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Util;

final class ClassInfo
{
    private static array $descendants = [];

    private static array $subclasses = [];

    /**
     * Returns true if the given class or one of its ancestors uses the given trait.
     *
     * Note: If the trait is used by another trait, which in turns is used by the class, this will not return true.
     */
    public static function classUsesTrait(string $className, string $traitName): bool
    {
        $ancestors = self::ancestorsOf($className);
        return !empty(array_intersect($ancestors, class_uses($traitName)));
    }

    /**
     * Get an array of ancestors for the given class. Optionally includes the class itself
     * as the first item in the array.
     *
     * This is basically just a convenient alias for class_parents() for when you want the
     * class itself to also be returned.
     */
    public static function ancestorsOf(string $className, bool $includeSelf = true): array
    {
        $ancestors = class_parents($className);
        if ($includeSelf) {
            $ancestors = [$className => $className] + $ancestors;
        }
        return $ancestors;
    }

    /**
     * Get an array of descendants for the given class. Optionally includes the class itself
     * as the first item in the array.
     */
    public static function descendantsOf(string $className, bool $includeSelf = true): array
    {
        if (empty(self::$descendants[$className])) {
            foreach (self::subclassesOf($className, false) as $subclass) {
                self::$descendants[$className] = self::descendantsOf($subclass);
            }
        }

        $descendants = self::$descendants[$className];
        if ($includeSelf) {
            $descendants = [$className => $className] + $descendants;
        }
        return $descendants;
    }

    /**
     * Get an array of subclasses for the given class. Optionally includes the class itself
     * as the first item in the array.
     */
    public static function subclassesOf(string $className, bool $includeSelf = true): array
    {
        if (empty(self::$subclasses[$className])) {
            foreach (get_declared_classes() as $candidateClass) {
                if (is_subclass_of($candidateClass, $className)) {
                    self::$subclasses[$className][$candidateClass] = $candidateClass;
                }
            }
        }

        $subclasses = self::$subclasses[$className];
        if ($includeSelf) {
            $subclasses = [$className => $className] + $subclasses;
        }
        return $subclasses;
    }
}

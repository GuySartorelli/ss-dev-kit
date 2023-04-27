<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Config;

use LogicException;
use ReflectionProperty;
use Silverstripe\DevStarterKit\Util\ArrayLib;
use Silverstripe\DevStarterKit\Util\ClassInfo;

/**
 * Any class using this trait has a defined configuration schema and can have configuration values
 * set in yaml configuration from plugins.
 *
 * You MUST declare a private static $configSchema property on any classes directly using this trait.
 * The $configSchema property is an array of config variable name to schema array.
 *
 * The schema array can be an empty array to use the default schema. Alternatively you can declare the following values:
 * - "type": @see Symfony\Component\Config\Definition\Builder\NodeBuilder->nodeMapping for valid type names
 * - "default": the default value (if any)
 * - "required": true if there must be a value defined
 * - "cannotBeEmpty": true if an empty value (e.g. null or empty string for a scalar type) should not be allowed
 */
trait Configurable
{
    /**
     * The full configuration schema for this class including schema defined in ancestors. Only for use by this trait.
     */
    private static $configSchemaRecursive = [];

    /**
     * Get the value for the configuration variable
     */
    public static function getConfig(string $configVar): mixed
    {
        return Config::getForClass(static::class, $configVar);
    }

    /**
     * Get the uninherited value for the configuration variable
     */
    public static function getConfigUninherited(string $configVar): mixed
    {
        return Config::getForClass(static::class, $configVar, false);
    }

    /**
     * Check if the class has a value for the configuration variable
     */
    public static function hasConfig(string $configVar): mixed
    {
        return Config::classHas(static::class, $configVar);
    }

    /**
     * Check if the class has an uninherited value for the configuration variable
     */
    public static function hasConfigUninherited(string $configVar): mixed
    {
        return Config::classHas(static::class, $configVar, false);
    }

    /**
     * Gets the full configuration schema for this class, including schema defined by any ancestor classes.
     */
    public static function getConfigurationSchema(): array
    {
        if (empty(static::$configSchemaRecursive)) {
            foreach (ClassInfo::ancestorsOf(static::class) as $class) {
                // Stop if we've hit a class that doesn't use the Configurable trait
                if (!ClassInfo::classUsesTrait($class, Configurable::class)) {
                    break;
                }

                // Ignore classes which don't declare a config array property - we'll throw an exception at the end
                // if no class has defined any config
                if (!property_exists($class, 'configSchema')) {
                    continue;
                }

                // Throw exception if the config property is maldefined
                $reflectionConfigSchema = new ReflectionProperty($class, 'configSchema');
                if (!$reflectionConfigSchema->isStatic() || !$reflectionConfigSchema->isPrivate() || !is_array($class::$configSchema)) {
                    throw new LogicException('$configSchema property on ' . static::class . ' is not defined correctly.');
                }

                $value = $reflectionConfigSchema->getValue();
                if (empty($value)) {
                    throw new LogicException('$configSchema property on ' . static::class . ' is empty.');
                }

                foreach ($value as $name => $schema) {
                    if (!is_array($schema)) {
                        throw new LogicException("Schema for config variable $name in $class must be an array!");
                    }

                    // Don't inherit default values - hopefully this means we can use getConfigUninherited() and only
                    // get values set for THIS class, including defaults.
                    if ($class !== self::class) {
                        unset($schema['default']);
                    }

                    // Ensure schema from ancestors is included, but any schema lower down the hierarchy ladder take precendence.
                    if (!isset(static::$configSchemaRecursive[$name])) {
                        static::$configSchemaRecursive[$name] = $schema;
                    } else {
                        static::$configSchemaRecursive[$name] = ArrayLib::arrayMergeRecursive(static::$configSchemaRecursive[$name], $schema);
                    }
                }
            }

            if (empty(static::$configSchemaRecursive)) {
                throw new LogicException('No configuration schema was provided for ' . static::class . ' or its configurable ancestors.');
            }

            // Define default schema if not defined anywhere in the class ancestry
            foreach (static::$configSchemaRecursive as $name => &$schema) {
                // Note there is no default "default" value. This is intentional.
                $schema['type'] ??= 'variable';
                $schema['required'] ??= false;
                $schema['cannotBeEmpty'] ??= false;
                $schema['canInherit'] ??= true;
            }
        }

        return static::$configSchemaRecursive;
    }
}

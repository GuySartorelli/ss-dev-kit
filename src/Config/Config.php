<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Config;

use LogicException;
use RuntimeException;
use Silverstripe\DevStarterKit\Util\ClassInfo;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

final class Config
{
    private static array $treeBuilders = [];

    private static array $config = [];

    /**
     * Initialise all configuration so it can be used during runtime
     */
    public static function boot(array $pluginPaths)
    {
        self::generateTreeBuilders();
        self::loadConfiguration($pluginPaths);
    }

    /**
     * Get a configuration variable's value for the given class.
     */
    public static function getForClass(string $className, string $configVar, bool $includeInheritedValues = true): mixed
    {
        if (isset(self::$config[$className]) && array_key_exists($configVar, self::$config[$className])) {
            return self::$config[$className][$configVar];
        }
        if ($includeInheritedValues) {
            foreach (ClassInfo::ancestorsOf($className, false) as $ancestor) {
                if (isset(self::$config[$ancestor]) && array_key_exists($configVar, self::$config[$ancestor])) {
                    return self::$config[$ancestor][$configVar];
                }
            }
        }
        throw new RuntimeException("Config variable $configVar not set for $className");
    }

    /**
     * Check if the class has a configuration variable.
     */
    public static function classHas(string $className, string $configVar, bool $includeInheritedValues = true): bool
    {
        if (isset(self::$config[$className]) && array_key_exists($configVar, self::$config[$className])) {
            return true;
        }
        if ($includeInheritedValues) {
            foreach (ClassInfo::ancestorsOf($className, false) as $ancestor) {
                if (isset(self::$config[$ancestor]) && array_key_exists($configVar, self::$config[$ancestor])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Generate an TreeBuilder instance for each configurable class, including descendants
     */
    private static function generateTreeBuilders(): void
    {
        foreach (class_uses(Configurable::class) as $class) {
            foreach (ClassInfo::descendantsOf($class) as $configurable) {
                // Skip class if it was already done - may happen if multiple classes in an inheritance
                // chain use the trait directly.
                if (array_key_exists($configurable, self::$treeBuilders)) {
                    continue;
                }

                $configSchema = $configurable::getConfigurationSchema();

                $tree = new TreeBuilder($configurable);
                $root = $tree->getRootNode();

                foreach ($configSchema as $name => $schema) {
                    $node = $root->children()->node($name, $schema['type'] ?? 'variable');
                    if (isset($schema['default'])) {
                        $node->defaultValue($schema['default']);
                    }
                    if ($schema['required']) {
                        $node->isRequired();
                    }
                    if ($schema['cannotBeEmpty']) {
                        $node->cannotBeEmpty();
                    }
                }

                self::$treeBuilders[$configurable] = $tree;
            }
        }
    }

    /**
     * Load configuration from plugin yaml files and merge it in with the default values from class config schema
     */
    private static function loadConfiguration(array $pluginPaths): void
    {
        // Fetch raw config from yaml files
        $config = [];
        if (!empty($pluginPaths)) {
            foreach (Finder::create()->in($pluginPaths)->path('/^_config\//')->files()->name('/\.ya?ml$/') as $file) {
                $filePath = $file->getPathname();
                if (!Path::isAbsolute($filePath)) {
                    throw new LogicException('Config file paths must be absolute');
                }
                $loader = new YamlFileLoader(new FileLocator($filePath));
                $loadedConfig = $loader->load($filePath);
                foreach ($loadedConfig as $class => $conf) {
                    $config[$class][] = $conf;
                }
            }
        }

        // Merge yaml config in with default config and store for later use
        $done = [];
        var_dump(class_uses(Configurable::class));
        foreach (class_uses(Configurable::class) as $class) {
            var_dump('dealing with ' . $class);
            foreach (ClassInfo::descendantsOf($class) as $configurable) {
                var_dump('dealing with ' . $configurable);
                $processor = new Processor();
                self::$config[$configurable] = $processor->process(self::$treeBuilders[$configurable]->buildTree(), $configs[$configurable] ?? []);
                $done[] = $configurable;
            }
        }

        // We don't allow any misconfiguration of configuration
        $notConfigurable = array_diff(array_keys($config), $done);
        if (!empty($notConfigurable)) {
            throw new LogicException("Configuration defined for class(es) that aren't configurable: " . implode(', ', $notConfigurable));
        }
    }
}

<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Config;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Config\Loader\FileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * Load configuration from a yaml file
 */
class YamlFileLoader extends FileLoader
{
    public function load(mixed $resource, ?string $type = 'yaml'): array
    {
        if (!$this->supports($resource, $type)) {
            throw new InvalidArgumentException('The resource is unsupported');
        }

        $path = $this->locator->locate($resource);

        if (!file_exists($path)) {
            throw new RuntimeException('The resource does not exist at ' . $path);
        }

        return Yaml::parseFile($path, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        if (!is_string($resource)) {
            return false;
        }

        if ($type === 'yaml' || $type === 'yml') {
            return true;
        }

        if ($type === null && str_ends_with($resource, '.yaml') || str_ends_with($resource, '.yml')) {
            return true;
        }

        return false;
    }
}

<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Compat;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class OS
{
    public static function getOS(): string
    {
        return PHP_OS_FAMILY;
    }

    public static function isWindows(): bool
    {
        return self::getOS() === 'Windows';
    }

    public static function isMacOS(): bool
    {
        return self::getOS() === 'Darwin';
    }

    public static function isLinux(): bool
    {
        return self::getOS() === 'Linux';
    }

    public static function isUnix(): bool
    {
        $unix = [
            'Linux',
            'Darwin',
            'Solaris',
            'BSD',
        ];
        return in_array(self::getOS(), $unix);
    }

    /**
     * Get the UID for the user running dev kit
     */
    public static function getUserID(): int
    {
        if (self::isUnix()) {
            return posix_getuid();
        } else {
            // This seems to not cause permissions issues with docker-for-windows
            return 1000;
        }
    }

    /**
     * Get the GID for the user running dev kit
     */
    public static function getGroupID(): int
    {
        if (self::isUnix()) {
            return posix_getgid();
        } else {
            // This seems to not cause permissions issues with docker-for-windows
            return 1000;
        }
    }

    /**
     * Try to get the directory on the host where composer cache is stored
     *
     * @see https://getcomposer.org/doc/03-cli.md#composer-cache-dir
     * Though it's worth noting that this isn't actually an exhaustive list of possible locations for the cache.
     */
    public static function getComposerCacheDir(): ?string
    {
        $filesystem = new Filesystem();
        $path = Path::canonicalize(getenv('COMPOSER_CACHE_DIR') ?: '');
        if ($path && $filesystem->exists($path)) {
            return $path;
        }

        $composerHome = Path::canonicalize(getenv('COMPOSER_HOME') ?: '');

        if (self::isWindows()) {
            $appData = Path::canonicalize(getenv('APPDATA') ?: '');
            $candidatePaths = [
                'Local/Composer',
                'Composer/cache',
            ];
            foreach ($candidatePaths as $candidate) {
                $path = Path::makeRelative($candidate, $appData);
                if ($filesystem->exists($path)) {
                    return realpath($path);
                }
            }
        } else {
            // Check cache home if it's available
            $cacheHome = Path::canonicalize(getenv('XDG_CACHE_HOME') ?: '');
            if ($cacheHome && $filesystem->exists($cacheHome)) {
                $candidate = Path::join($cacheHome, 'composer');
                if ($filesystem->exists($candidate)) {
                    return $candidate;
                }
            }
            // Check composer home if it's available
            if ($composerHome && $filesystem->exists($composerHome)) {
                $candidate = Path::join($composerHome, 'cache');
                if ($filesystem->exists($candidate)) {
                    return $candidate;
                }
            }
            // Check other likely paths
            $candidatePaths = [
                '~/.composer/cache',
                '~/.cache/composer',
            ];
            foreach ($candidatePaths as $candidate) {
                $path = Path::canonicalize($candidate);
                if ($filesystem->exists($path)) {
                    return $path;
                }
            }
        }
    }
}

<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Compat;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class Filesystem extends SymfonyFilesystem
{
    public function exists(string|iterable $files): bool
    {
        // Make sure the path is in a format valid for the given OS
        if (!OS::isUnix()) {
            if (!is_iterable($files)) {
                $files = [$files];
            }
            foreach ($files as $i => $file) {
                $files[$i] = realpath($file) ?: '';
            }
        }

        return parent::exists($files);
    }

    public function isDir(string $path): bool
    {
        if (!$this->exists($path)) {
            return false;
        }

        // Make sure the path is in a format valid for the given OS
        if (!OS::isUnix()) {
            return is_dir(realpath($path) ?: '');
        }

        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        if (!$this->exists($path)) {
            return false;
        }

        // Make sure the path is in a format valid for the given OS
        if (!OS::isUnix()) {
            return is_file(realpath($path) ?: '');
        }

        return is_file($path);
    }

    public function getFileContents(string $path): string|false
    {
        // Make sure the path is in a format valid for the given OS
        if (!OS::isUnix()) {
            return file_get_contents(realpath($path) ?: '');
        }

        return file_get_contents($path);
    }
}

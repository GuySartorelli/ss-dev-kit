<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Environment;

use LogicException;
use Silverstripe\DevKit\Compat\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

final class Environment
{
    private static Filesystem $filesystem;

    private string $baseDir;

    private string $name;

    private ?int $port;

    public const ATTACHED_ENV_FILE = '.attached-env';

    public const ENV_META_DIR = '.ss-dev-kit';

    public static function dirIsInEnv(string $dir): bool
    {
        $baseDir = self::findBaseDirForEnv($dir);
        return $baseDir !== null;
    }

    /**
     * @throws LogicException if not a new environment and $path is not in a valid environment.
     */
    public function __construct(string $path, bool $isNew = false, bool $allowMissing = false)
    {
        if (Path::isAbsolute($path)) {
            $path = Path::canonicalize($path);
        } else {
           $path = Path::makeAbsolute($path, getcwd());
        }
        $this->setProjectRoot($path, $isNew, $allowMissing);
        $this->setName();
    }

    public function setPort(?int $port)
    {
        $this->port = $port;
    }

    public function getPort(): ?int
    {
        if (!isset($this->port)) {
            $composeData = $this->getDockerComposeData();
            $ports = $composeData['services']['webserver']['ports'] ?? [];
            foreach ($ports as $portMap) {
                list($localPort, $containerPort) = explode(':', $portMap);
                if ($containerPort === '80') {
                    $this->port = (int) $localPort;
                }
            }
        }
        return $this->port ?? null;
    }

    public function getProjectRoot(): string
    {
        return $this->baseDir;
    }

    /**
     * Gets the absolute path to the dev kit directory inside a given project environment
     */
    public function getMetaDir(): string
    {
        return Path::join($this->getProjectRoot(), self::ENV_META_DIR);
    }

    public function getDockerDir(): string
    {
        return Path::join($this->getMetaDir(), 'docker');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHostName(): string
    {
        return $this->getName() . '.' . getenv('DT_DEFAULT_HOST_SUFFIX');
    }

    public function getBaseURL(): string
    {
        if ($port = $this->getPort()) {
            return "http://localhost:$port";
        }
        return "http://{$this->getHostName()}";
    }

    public function isAttachedEnv()
    {
        return self::getFileSystem()->exists(Path::join($this->getMetaDir(), self::ATTACHED_ENV_FILE));
    }

    public function exists(): bool
    {
        return self::getFileSystem()->isDir($this->getProjectRoot());
    }

    /**
     * Parse and return the docker-compose.yml file contents
     */
    public function getDockerComposeData(): array
    {
        $filePath = Path::join($this->getDockerDir(), 'docker-compose.yml');
        if (self::getFileSystem()->isFile($filePath)) {
            return Yaml::parseFile($filePath);
        }
        return [];
    }

    private function setProjectRoot(string $candidate, bool $isNew, bool $allowMissing): void
    {
        if ($isNew) {
            $this->baseDir = $candidate;
            return;
        }

        $this->baseDir = (string) self::findBaseDirForEnv($candidate);

        if (!$this->baseDir && !$allowMissing) {
            throw new LogicException("Environment path '$candidate' is not inside a valid environment.");
        }
    }

    private function setName()
    {
        $this->name = $this->getAttachedEnvName() ?? basename($this->getProjectRoot());
    }

    private function getAttachedEnvName()
    {
        $path = Path::join($this->getProjectRoot(), self::ATTACHED_ENV_FILE);
        $fs = self::getFileSystem();
        if ($fs->exists($path)) {
            return self::getFileSystem()->getFileContents($path);
        }
        return null;
    }

    private static function findBaseDirForEnv(string $candidate): ?string
    {
        if (!self::getFileSystem()->isDir($candidate)) {
            throw new LogicException("'$candidate' is not a directory.");
        }

        $stopAtDirs = [
            '/',
            '/home',
        ];

        // Recursively check the proposed path and its parents for the meta dir.
        while ($candidate && !in_array($candidate, $stopAtDirs)) {
            // If we find the environment meta directory, we're in a project.
            if (self::getFileSystem()->isDir(Path::join($candidate, self::ENV_META_DIR))) {
                return $candidate;
            }

            // If the candidate doesn't have the meta dir, check its parent next.
            $candidate = Path::getDirectory($candidate);
        }

        return null;
    }

    private static function getFileSystem(): Filesystem
    {
        if (!isset(self::$filesystem)) {
            self::$filesystem = new Filesystem();
        }
        return self::$filesystem;
    }
}

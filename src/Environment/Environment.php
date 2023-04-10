<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Environment;

use LogicException;
use stdClass;
use Symfony\Component\Filesystem\Path;

final class Environment
{
    private string $baseDir;

    private string $name;

    private ?int $port;

    public const ATTACHED_ENV_FILE = '.attached-env';

    public const ENV_META_DIR = '.ss-dev-starter-kit';

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
        if (isset($this->port)) {
            return $this->port;
        }
        // @TODO Get the port from the meta directory
    }

    public function getProjectRoot(): string
    {
        return $this->baseDir;
    }

    /**
     * Gets the absolute path to the starter kit directory inside a given project environment
     */
    public function getmetaDir(): string
    {
        return Path::join($this->getProjectRoot(), self::ENV_META_DIR);
    }

    public function getDockerDir(): string
    {
        return Path::join($this->getmetaDir(), 'docker');
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
        return file_exists(Path::join($this->getmetaDir(), self::ATTACHED_ENV_FILE));
    }

    public function exists(): bool
    {
        return is_dir($this->getProjectRoot());
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
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    private static function findBaseDirForEnv(string $candidate): ?string
    {
        if (!is_dir($candidate)) {
            throw new LogicException("'$candidate' is not a directory.");
        }

        $stopAtDirs = [
            '/',
            '/home',
        ];

        // Recursively check the proposed path and its parents for the meta dir.
        while ($candidate && !in_array($candidate, $stopAtDirs)) {
            // If we find the environment meta directory, we're in a project.
            if (is_dir(Path::join($candidate, self::ENV_META_DIR))) {
                return $candidate;
            }

            // If the candidate doesn't have the meta dir, check its parent next.
            $candidate = Path::getDirectory($candidate);
        }

        return null;
    }
}

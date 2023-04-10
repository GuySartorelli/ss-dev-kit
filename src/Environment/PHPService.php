<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Environment;

use RuntimeException;
use Silverstripe\DevStarterKit\IO\CommandOutput;
use Silverstripe\DevStarterKit\Trait\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

class PHPService
{
    use UsesDocker;

    private Environment $env;

    private CommandOutput $output;

    public function __construct(Environment $environment, CommandOutput $output)
    {
        $this->env = $environment;
        $this->output = $output;
    }

    /**
     * Get the CLI PHP version of the current webserver container
     *
     * @param bool $fullVersion Whether to return the full version (e.g. '8.1.17') or just
     * the minor (e.g. '8.1')
     */
    public function getCliPhpVersion(bool $fullVersion = false): string
    {
        $this->output->writeln('Checking CLI PHP version', OutputInterface::VERBOSITY_DEBUG);
        $echo = $fullVersion ? 'PHP_VERSION' : "PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION";
        $dockerResult = $this->getDockerService()->exec(
            'echo $(php -r "echo ' . $echo . ';")',
            outputType: DockerService::OUTPUT_TYPE_RETURN
        );
        if (!is_string($dockerResult)) {
            throw new RuntimeException("Error fetching PHP version");
        }
        $version = trim($dockerResult);
        $versionRegex = '\d+\.\d+(\.\d+)?';
        // Strip out xdebug error if there is one.
        if (str_contains($version, 'Could not connect to debugging client')) {
            $version = preg_replace('/.*(' . $versionRegex . ')$/s', '$1', $version);
        }
        if (!preg_match('/^(' . $versionRegex . ')$/', $version)) {
            throw new RuntimeException("Error fetching PHP version: $version");
        }
        return $version;
    }

    /**
     * Get the Apache PHP version of the current webserver container
     */
    public function getApachePhpVersion(): string
    {
        $this->output->writeln('Checking apache PHP version', OutputInterface::VERBOSITY_DEBUG);
        $dockerResult = $this->getDockerService()->exec(
            'ls /etc/apache2/mods-enabled/ | grep php[0-9.]*\.conf',
            outputType: DockerService::OUTPUT_TYPE_RETURN
        );
        $version = trim($dockerResult ?: '');
        $regex = '/^php([0-9.]+)\.conf$/';
        if ($dockerResult === false || !preg_match($regex, $version)) {
            throw new RuntimeException("Error fetching PHP version: $version");
        }
        $version = preg_replace($regex, '$1', $version);
        return $version;
    }

    /**
     * Get the statis of XDebug in the current webserver container for the current CLI
     * or passed in PHP version
     *
     * Assumes CLI and apache PHP versions are in sync
     */
    public function debugIsEnabled(?string $version = null): bool
    {
        $this->output->writeln('Checking if xdebug is enabled', OutputInterface::VERBOSITY_DEBUG);
        // Assume by this point the PHP versions are the same.
        $version ??= $this->getCliPhpVersion();
        $path = $this->getDebugPath($version);
        $dockerResult = $this->getDockerService()->exec("cat {$path}", outputType: DockerService::OUTPUT_TYPE_RETURN);
        $debug = trim($dockerResult ?: '');
        if ($dockerResult === false) {
            throw new RuntimeException("Error fetching debug status: $debug");
        }
        return $debug !== '' && !str_starts_with($debug, ';');
    }

    /**
     * Get the path for the XDebug config for the given PHP version
     */
    public function getDebugPath(string $phpVersion): string
    {
        return "/etc/php/{$phpVersion}/mods-available/xdebug.ini";
    }

    /**
     * Swap both CLI and Apache PHP versions to some new specific version
     *
     * @TODO set some flag or config for docker compose or the docker image entrypoint so that the correct PHP version is used on startup
     */
    public function swapToVersion(string $version): bool
    {
        if (!static::versionIsAvailable($version)) {
            throw new RuntimeException("PHP $version is not available.");
        }

        $oldVersionCLI = $this->getCliPhpVersion();
        $oldVersionApache = $this->getApachePhpVersion();

        if ($oldVersionCLI === $oldVersionApache && $oldVersionApache === $version) {
            $this->output->writeln("Already using version <info>$version</info> - skipping.");
            return true;
        }

        $success = true;

        if ($oldVersionCLI !== $version) {
            $this->output->writeln("Swapping CLI PHP from <info>$oldVersionCLI</info> to <info>$version</info>.");
            $success = $success && $this->swapCliToVersion($version);
        }

        if ($oldVersionApache !== $version) {
            $this->output->writeln("Swapping Apache PHP from <info>$oldVersionApache</info> to <info>$version</info>.");
            $success = $success && $this->swapApacheToVersion($oldVersionApache, $version);
        }

        return $success;
    }

    private function swapCliToVersion(string $toVersion): bool
    {
        $command = <<<EOL
        rm /etc/alternatives/php && \\
        ln -s /usr/bin/php{$toVersion} /etc/alternatives/php
        EOL;

        return $this->getDockerService()->exec($command, asRoot: true);
    }

    private function swapApacheToVersion(string $fromVersion, string $toVersion): bool
    {
        $command = <<<EOL
        rm /etc/apache2/mods-enabled/php{$fromVersion}.conf && \\
        rm /etc/apache2/mods-enabled/php{$fromVersion}.load && \\
        ln -s /etc/apache2/mods-available/php$toVersion.conf /etc/apache2/mods-enabled/php$toVersion.conf && \\
        ln -s /etc/apache2/mods-available/php$toVersion.load /etc/apache2/mods-enabled/php$toVersion.load
        EOL;

        $success = $this->getDockerService()->exec($command, asRoot: true);

        if (!$success) {
            return false;
        }

        return $this->getDockerService()->restart(DockerService::CONTAINER_WEBSERVER, timeout: 0);
    }

    /**
     * Check whether some PHP version is available to be used
     */
    public static function versionIsAvailable(string $version): bool
    {
        // @TODO handle php versions correctly - either ACTUALLY checking the docker container or as a const
        $versions = explode(',', getenv('DT_PHP_VERSIONS'));
        return in_array($version, $versions);
    }

    /**
     * Check which PHP versions are available to be used
     * @TODO consider getting versions from the docker container instead
     */
    public static function getAvailableVersions(): array
    {
        return explode(',', getenv('DT_PHP_VERSIONS'));
    }
}

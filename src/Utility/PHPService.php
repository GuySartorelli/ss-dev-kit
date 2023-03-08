<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Utility;

use RuntimeException;
use Silverstripe\DevStarterKit\Trait\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

class PHPService
{
    use UsesDocker;

    private Environment $env;

    private OutputInterface $output;

    public function __construct(Environment $environment, OutputInterface $output)
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
        $echo = $fullVersion ? 'PHP_VERSION' : "PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION";
        $dockerResult = $this->runDockerCommand(
            'echo $(php -r "echo ' . $echo . ';")',
            returnOutput: true
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
        $dockerResult = $this->runDockerCommand(
            'ls /etc/apache2/mods-enabled/ | grep php[0-9.]*\.conf',
            returnOutput: true
        );
        $version = trim($dockerResult ?: '');
        $regex = '/^php([0-9.]+)\.conf$/';
        if ($dockerResult === Command::FAILURE || !preg_match($regex, $version)) {
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
        // Assume by this point the PHP versions are the same.
        $version ??= $this->getCliPhpVersion();
        $path = $this->getDebugPath($version);
        $dockerResult = $this->runDockerCommand("cat {$path}", returnOutput: true);
        $debug = trim($dockerResult ?: '');
        if ($dockerResult === Command::FAILURE) {
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
    public function swapToVersion(string $version): int
    {
        if (!static::versionIsAvailable($version)) {
            throw new RuntimeException("PHP $version is not available.");
        }

        $oldVersionCLI = $this->getCliPhpVersion();
        $oldVersionApache = $this->getApachePhpVersion();

        if ($oldVersionCLI === $oldVersionApache && $oldVersionApache === $version) {
            $this->output->writeln("<info>Already using version $version - skipping.</info>");
            return Command::SUCCESS;
        }

        $success = true;

        if ($oldVersionCLI !== $version) {
            $this->output->writeln("<info>Swapping CLI PHP from $oldVersionCLI to $version.</info>");
            $success = $success && $this->swapCliToVersion($version);
        }

        if ($oldVersionApache !== $version) {
            $this->output->writeln("<info>Swapping Apache PHP from $oldVersionApache to $version.</info>");
            $success = $success &&$this->swapApacheToVersion($oldVersionApache, $version);
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    private function swapCliToVersion(string $toVersion): bool
    {
        $command = <<<EOL
        rm /etc/alternatives/php && \\
        ln -s /usr/bin/php{$toVersion} /etc/alternatives/php
        EOL;

        return $this->runDockerCommand($command, asRoot: true) === Command::SUCCESS;
    }

    private function swapApacheToVersion(string $fromVersion, string $toVersion): bool
    {
        $command = <<<EOL
        rm /etc/apache2/mods-enabled/php{$fromVersion}.conf && \\
        rm /etc/apache2/mods-enabled/php{$fromVersion}.load && \\
        ln -s /etc/apache2/mods-available/php$toVersion.conf /etc/apache2/mods-enabled/php$toVersion.conf && \\
        ln -s /etc/apache2/mods-available/php$toVersion.load /etc/apache2/mods-enabled/php$toVersion.load
        EOL;

        return $this->runDockerCommand($command, asRoot: true, requiresRestart: true) === Command::SUCCESS;
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

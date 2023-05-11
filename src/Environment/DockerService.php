<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Environment;

use InvalidArgumentException;
use Silverstripe\DevKit\Compat\Filesystem;
use Silverstripe\DevKit\IO\CommandOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final class DockerService
{
    private Environment $env;

    private CommandOutput $output;

    /**
     * The output is returned from the method call
     */
    public const OUTPUT_TYPE_RETURN = 0;

    /**
     * The output is only ever output to the terminal in debug mode
     */
    public const OUTPUT_TYPE_DEBUG = 1;

    /**
     * The output is output to the terminal following the normal step level rules
     */
    public const OUTPUT_TYPE_NORMAL = 2;

    /**
     * The output is always output to the terminal regardless of step level and verbosity (except quiet mode)
     */
    public const OUTPUT_TYPE_ALWAYS = 3;

    public const CONTAINER_WEBSERVER = 'webserver';

    public const CONTAINER_DATABASE = 'database';

    public function __construct(Environment $environment, CommandOutput $output)
    {
        $this->env = $environment;
        $this->output = $output;
    }

    /**
     * Get an associative array of docker container statuses
     *
     * @return string[]
     */
    public function getContainersStatus(): array
    {
        $cmd = [
            'docker',
            'compose',
            'ps',
            '--all',
            '--format=json',
        ];

        // @TODO refactor runCommand (and consequently things calling it) to optionally return output instead of outputting it
        // @TODO then we can just use $this->dockerComposeCommand() here
        $process = new Process($cmd, $this->env->getDockerDir());
        $process->run();

        if (!$process->isSuccessful()) {
            // @TODO more consistent error messaging
            // @TODO use $process->getErrorOutput(); here to indicate what went wrong
            $this->output->warning("Couldn't get status of docker containers.");
            return null;
        }

        $containers = [
            self::CONTAINER_WEBSERVER => 'missing',
            self::CONTAINER_DATABASE => 'missing',
        ];
        foreach (json_decode($process->getOutput(), true) as $container) {
            $name = str_replace($this->env->getName() . '_', '', $container['Name']);
            $containers[$name] = $container['State'];
            if (!empty($container['Health'])) {
                $containers[$name] .= ' (' . $container['Health'] . ')';
            }
        }
        return $containers;
    }

    /**
     * Create and start docker containers
     */
    public function up(
        bool $build = false,
        bool $noBuild = false,
        bool $forceRecreate = false,
        bool $noRecreate = false,
        bool $removeOrphans = false,
        bool $wait = true,
        int $outputType = self::OUTPUT_TYPE_NORMAL
    ): bool|string
    {
        if ($build && $noBuild) {
            throw new InvalidArgumentException('Cann use build and no-build at the same time');
        }
        if ($forceRecreate && $noRecreate) {
            throw new InvalidArgumentException('Cann use force-recreate and no-recreate at the same time');
        }

        $options = [];
        if ($build) {
            $options[] = '--build';
        }
        if ($noBuild) {
            $options[] = '--no-build';
        }
        if ($forceRecreate) {
            $options[] = '--force-recreate';
        }
        if ($noRecreate) {
            $options[] = '--no-recreate';
        }
        if ($removeOrphans) {
            $options[] = '--remove-orphans';
        }
        if ($wait) {
            $options[] = '--wait';
        }
        $options[] = '-d';

        return $this->dockerComposeCommand('up', $options, $outputType);
    }

    /**
     * Stop and remove containers, networks, and optionally images and volumes.
     *
     * @param bool $images Remove images used by services if they don't have custom tags
     * @param bool $volumes Remove named volumes declared in the volumes section of the Compose file and anonymous volumes attached to containers
     * @param int $outputType One of the OUTPUT_TYPE constants.
     */
    public function down(bool $removeOrphans = false, bool $images = false, bool $volumes = false, int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        $options = [];
        if ($removeOrphans) {
            $options[] = '--remove-orphans';
        }
        if ($volumes) {
            $options[] = '--volumes';
        }
        if ($images) {
            $options[] = '--rmi=local';
        }

        return $this->dockerComposeCommand('down', $options, $outputType);
    }

    /**
     * Start services for containers that already exist
     */
    public function start(int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        return $this->dockerComposeCommand('start', outputType: $outputType);
    }

    /**
     * Stop services without stopping or removing containers
     */
    public function stop(int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        return $this->dockerComposeCommand('stop', outputType: $outputType);
    }

    /**
     * Restart the container(s).
     *
     * If no container is passed in, it restarts everything.
     */
    public function restart(
        string $container = '',
        bool $noDeps = false,
        ?int $timeout = null,
        int $outputType = self::OUTPUT_TYPE_NORMAL
    ): bool|string
    {
        $options = [];
        if ($noDeps) {
            $options[] = '--no-deps';
        }
        if ($timeout !== null) {
            $options[] = "-t$timeout";
        }
        if ($container) {
            $options[] = $container;
        }

        return $this->dockerComposeCommand('restart', $options, $outputType);
    }

    /**
     * Run any docker compose command.
     */
    public function dockerComposeCommand(string $command, array $options = [], int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        return $this->runCommand(
            [
                'docker',
                'compose',
                $command,
                ...$options
            ],
            $outputType
        );
    }

    /**
     * Copies a file from a docker container to the host's filesystem.
     *
     * @param string $container Which container to copy from.
     * Usually one of self::CONTAINER_WEBSERVER or self::CONTAINER_DATABASE
     * @param string $copyFrom Full file path to copy from in the container.
     * @param string $copyTo Full file path to copy to on the host.
     * @param int $outputType One of the OUTPUT_TYPE constants.
     */
    public function copyFromContainer(string $container, string $copyFrom, string $copyTo, int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        $command = [
            'docker',
            'cp',
            $this->env->getName() . "_$container:$copyFrom",
            $copyTo,
        ];
        return $this->runCommand($command, $outputType);
    }

    public function copyToContainer(string $container, string $copyFrom, string $copyTo, int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        $command = [
            'docker',
            'cp',
            $copyFrom,
            $this->env->getName() . "_$container:$copyTo",
        ];
        return $this->runCommand($command, $outputType);
    }

    /**
     * Run some command in the webserver docker container - optionally as root.
     *
     * @throws InvalidArgumentException if $exec is an empty string.
     */
    public function exec(
        string $exec,
        ?string $workingDir = null,
        bool $asRoot = false,
        bool $interactive = true,
        $container = self::CONTAINER_WEBSERVER,
        int $outputType = self::OUTPUT_TYPE_NORMAL
    ): bool|string
    {
        if (empty($exec)) {
            throw new InvalidArgumentException('$exec cannot be an empty string');
        }
        $shouldOutput = $this->shouldOutputToTerminal($outputType);
        $execCommand = [
            'docker',
            'exec',
            ...($shouldOutput && Process::isTtySupported() ? ['-t'] : []),
            ...($shouldOutput && $interactive ? ['-i'] : []),
            ...($workingDir !== null ? ['--workdir', $workingDir] : []),
            ...($workingDir === null && $container === self::CONTAINER_WEBSERVER ? ['--workdir', '/var/www'] : []),
            ...($asRoot ? [] : ['-u', $this->getUidForWwwdata()]),
            $this->env->getName() . '_' . $container,
            'env',
            'TERM=xterm-256color',
            'bash',
            '-c',
            $exec,
        ];
        return $this->runCommand($execCommand, $outputType);
    }

    private function runCommand(array $command, int $outputType = self::OUTPUT_TYPE_NORMAL): bool|string
    {
        $this->output->writeln('Running command in docker container: "' . implode(' ', $command) . '"', OutputInterface::VERBOSITY_DEBUG);

        $shouldOutput = $this->shouldOutputToTerminal($outputType);
        $useTty = $shouldOutput && Process::isTtySupported();
        $process = new Process($command, $this->env->getDockerDir());
        $process->setTimeout(null);

        if ($useTty) {
            // TTY output is dumped directly into the terminal
            $this->output->clearProgressBar();
            $process->setTty(true);
            $process->run();
        } else {
            // Start the process, with a callback to handle output
            $process->start(
                function($type, $data) use ($shouldOutput, $outputType) {
                    if ($shouldOutput) {
                        $this->output->write($data);
                    } elseif ($this->output->isDebug() && $outputType === self::OUTPUT_TYPE_RETURN) {
                        $this->output->write("Docker output: $data");
                    } else {
                        $this->output->advanceProgressBar();
                    }
                }
            );
            // Advance the progressbar every second if we're not using the actual output
            if (!$shouldOutput && !$this->output->isDebug()) {
                while ($process->isRunning()) {
                    $process->checkTimeout();
                    $this->output->advanceProgressBar();
                    usleep(1000);
                }
            }
            // Make absolutely sure the process has finished
            $process->wait();
        }

        if (!$process->isSuccessful()) {
            $this->output->writeln('Docker exit code: ' . $process->getExitCode(), OutputInterface::VERBOSITY_DEBUG);
        }

        return $outputType === self::OUTPUT_TYPE_RETURN ? $process->getOutput() : $process->isSuccessful();
    }

    private function shouldOutputToTerminal(int $outputType): bool
    {
        switch ($outputType) {
            case self::OUTPUT_TYPE_RETURN:
                // We do want to output returned data in debug mode, but if we return true here and TTY is enabled
                // we won't be able to capture and therefore return the data
                return false;
            case self::OUTPUT_TYPE_ALWAYS:
                return true;
            case self::OUTPUT_TYPE_DEBUG:
                return $this->output->isDebug();
            case self::OUTPUT_TYPE_NORMAL:
                return $this->output->stepWillOutput();
            default:
                throw new InvalidArgumentException('$outputType must be one of the OUTPUT_TYPE constants');
        }
    }

    private function getUidForWwwdata(): int
    {
        $envFile = Path::join($this->env->getDockerDir(), '.env');
        $dotenv = new Dotenv();
        $fs = new Filesystem();
        $envVars = $dotenv->parse($fs->getFileContents($envFile), $envFile);
        return (int) $envVars['WWW_DATA_UID'];
    }
}

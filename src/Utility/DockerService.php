<?php

namespace Silverstripe\DevStarterKit\Utility;

use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class DockerService
{
    private Environment $env;

    private OutputInterface $output;

    public const CONTAINER_WEBSERVER = '_webserver';

    public const CONTAINER_DATABASE = '_database';

    public function __construct(Environment $environment, OutputInterface $output)
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
            $msg = "Couldn't get status of docker containers.";
            // @TODO use $process->getErrorOutput(); here to indicate what went wrong
            if ($this->output instanceof SymfonyStyle) {
                $this->output->warning($msg);
            } else {
                $this->output->writeln($msg);
            }
            return null;
        }

        $containers = [
            'webserver container' => 'missing',
            'database container' => 'missing',
        ];
        foreach (json_decode($process->getOutput(), true) as $container) {
            $name = str_replace($this->env->getName() . '_', '', $container['Name']) . ' container';
            $containers[$name] = $container['State'];
        }
        return $containers;
    }

    /**
     * Create and start docker containers
     */
    public function up(bool $fullBuild = false): bool
    {
        $options = [];
        if ($fullBuild) {
            $options[] = '--build';
        }
        $options[] = '-d';

        return $this->dockerComposeCommand('up', $options);
    }

    /**
     * Stop and remove containers, networks, and optionally images and volumes.
     *
     * @param bool $images Remove images used by services if they don't have custom tags
     * @param bool $volumes Remove named volumes declared in the volumes section of the Compose file and anonymous volumes attached to containers
     */
    public function down(bool $images = false, bool $volumes = false): bool
    {
        $options = ['--remove-orphans'];
        if ($volumes) {
            $options[] = '--volumes';
        }
        if ($images) {
            $options[] = '--rmi=local';
        }

        return $this->dockerComposeCommand('down', $options);
    }

    /**
     * Start services for containers that already exist
     */
    public function start(): bool
    {
        return $this->dockerComposeCommand('start');
    }

    /**
     * Stop services without stopping or removing containers
     */
    public function stop(): bool
    {
        return $this->dockerComposeCommand('stop');
    }

    /**
     * Restart the container(s).
     *
     * If no container is passed in, it restarts everything.
     */
    public function restart(string $container = '', ?int $timeout = null): bool
    {
        $options = [];
        if ($timeout !== null) {
            $options[] = "-t$timeout";
        }
        if ($container) {
            $options[] = ltrim($container, '_');
        }

        return $this->dockerComposeCommand('restart', $options);
    }

    /**
     * Run any docker compose command.
     */
    public function dockerComposeCommand(string $command, array $options = []): bool
    {
        return $this->runCommand([
            'docker',
            'compose',
            $command,
            ...$options
        ]);
    }

    /**
     * Copies a file from a docker container to the host's filesystem.
     *
     * @param string $container Which container to copy from.
     * Usually one of self::CONTAINER_WEBSERVER or self::CONTAINER_DATABASE
     * @param string $copyFrom Full file path to copy from in the container.
     * @param string $copyTo Full file path to copy to on the host.
     */
    public function copyFromContainer(string $container, string $copyFrom, string $copyTo): bool
    {
        $command = [
            'docker',
            'cp',
            $this->env->getName() . $container . ":$copyFrom",
            $copyTo,
        ];
        return $this->runCommand($command);
    }

    /**
     * Run some command in the webserver docker container - optionally as root.
     * @throws InvalidArgumentException
     */
    public function exec(
        string $exec,
        ?string $workingDir = null,
        bool $asRoot = false,
        bool $interactive = true,
        $container = self::CONTAINER_WEBSERVER,
        bool $returnOutput = false
    ): bool|string
    {
        if (empty($exec)) {
            throw new InvalidArgumentException('$exec cannot be an empty string');
        }
        $execCommand = [
            'docker',
            'exec',
            ...(!$returnOutput && Process::isTtySupported() ? ['-t'] : []),
            ...(!$returnOutput && $interactive ? ['-i'] : []),
            ...($workingDir !== null ? ['--workdir', $workingDir] : []),
            ...($workingDir === null && $container === self::CONTAINER_WEBSERVER ? ['--workdir', '/var/www'] : []),
            ...($asRoot ? [] : ['-u', '1000']), // @TODO we'll need to get the same user as is declared for www-data, in case there's multiple users on the machine where the command is run
            $this->env->getName() . $container,
            'env',
            'TERM=xterm-256color',
            'bash',
            '-c',
            $exec,
        ];
        return $this->runCommand($execCommand, $interactive, $returnOutput);
    }

    private function runCommand(array $command, bool $returnOutput = false): bool|string
    {
        // If returning output, pipe stderr into stdout.
        if ($returnOutput) {
            $command[] = '2>&1';
        }

        $process = new Process($command, $this->env->getDockerDir());
        $process->setTimeout(null);
        if (!$returnOutput && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run();

        return $returnOutput ? $process->getOutput() : $process->isSuccessful();
    }
}

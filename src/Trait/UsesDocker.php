<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Trait;

use Silverstripe\DevStarterKit\Utility\DockerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

trait UsesDocker
{
    private DockerService $docker;

    protected function getDockerService()
    {
        if (!isset($this->docker)) {
            $this->docker = new DockerService($this->env, $this->output);
        }
        return $this->docker;
    }

    /**
     * Run a command in the webserver container for the current environment.
     *
     * @return string|integer|boolean
     * If $output is null or a BufferedOutput, the return type will be the output value from docker unless something goes wrong.
     * If anything goes wrong, Command::FAILURE will be returned.
     * If nothing goes wrong and $output was passed with some non-BufferedOutput, false will be returned.
     */
    protected function runDockerCommand(
        string $command,
        ?string $workingDir = null,
        bool $asRoot = false,
        bool $requiresRestart = false,
        bool $interactive = true,
        string $container = DockerService::CONTAINER_WEBSERVER,
        bool $returnOutput = false
    ): string|int
    {
        // @TODO standardised output styling/verbosity
        $this->output->writeln("Running command in docker container: '$command'");

        $dockerResult = $this->getDockerService()->exec($command, $workingDir, $asRoot, $interactive, $container, $returnOutput);
        if ($dockerResult === false) {
            $msg = 'Problem occured while running command in docker container.';
            if ($this->output instanceof SymfonyStyle) {
                $this->output->error($msg);
            } else {
                $this->output->writeln("Error: $msg");
            }
            // @TODO probably have a custom exception (or use one from the Process component) and throw that instead.
            return Command::FAILURE;
        }

        if ($requiresRestart) {
            $success = $this->getDockerService()->restart($container, timeout: 0);
            if (!$success) {
                $msg = 'Could not restart container.';
                if ($this->output instanceof SymfonyStyle) {
                    $this->output->error($msg);
                } else {
                    $this->output->writeln("Error: $msg");
                }
                // @TODO probably have a custom exception (or use one from the Process component) and throw that instead.
                return Command::FAILURE;
            }
        }

        if ($returnOutput) {
            return $dockerResult;
        }
        return Command::SUCCESS;
    }
}

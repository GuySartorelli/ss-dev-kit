<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Docker;

use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Silverstripe\DevKit\IO\StepLevel;
use Silverstripe\DevKit\Environment\DockerService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Effectively an environment-aware alias for "docker compose exec"
 */
class Exec extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:exec';

    protected static $defaultDescription = 'Execute a command in a running container.';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Executing command in docker container");

        // Pull down docker
        $success = $this->getDockerService()->exec(
            implode(' ', $this->input->getArgument('cmd')),
            $this->input->getOption('workdir'),
            $this->input->getOption('privileged'),
            !$this->input->getOption('no-interaction'),
            $this->input->getOption('container') ?? '',
            DockerService::OUTPUT_TYPE_ALWAYS
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return Command::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Command executed successfully');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['exec']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Basically an environment-aware alias for "docker compose exec".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be execed.',
            './'
        );
        $this->addOption(
            'container',
            'c',
            InputOption::VALUE_REQUIRED,
            'The name (as defined in the docker compose file) of the service to be execed (usually one of "webserver" or "database"). If ommitted, all containers will be execed.',
            DockerService::CONTAINER_WEBSERVER
        );
        $this->addOption(
            'detach',
            'd',
            description: 'Run command in the background.'
        );
        $this->addOption(
            'privileged',
            'r',
            description: 'Give extended privileges to the process.'
        );
        $this->addOption(
            'workdir',
            'w',
            InputOption::VALUE_REQUIRED,
            'Path to workdir directory for this command. Default is "/var/www" if --container is "webserver"',
        );
        $this->addArgument(
            'cmd',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'The command to be run in the docker container. If you need to pass options in the docker command, use "--" (e.g. "docker:exec -- ls -a app/src")'
        );
    }
}

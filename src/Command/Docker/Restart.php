<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Docker;

use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Silverstripe\DevKit\IO\StepLevel;
use Silverstripe\DevKit\Environment\DockerService;
use Symfony\Component\Console\Input\InputOption;

/**
 * Effectively an environment-aware alias for "docker compose restart"
 */
class Restart extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:restart';

    protected static $defaultDescription = 'Restarts all stopped and running docker containers, or the specified container only.';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Restarting docker container(s)");

        // Pull down docker
        $success = $this->getDockerService()->restart(
            $this->input->getOption('container') ?? '',
            $this->input->getOption('no-deps'),
            DockerService::OUTPUT_TYPE_ALWAYS
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return Command::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Docker container(s) Restarted');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['restart']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Basically an environment-aware alias for "docker compose restart".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be restarted.',
            './'
        );
        $this->addOption(
            'container',
            'c',
            mode: InputOption::VALUE_REQUIRED,
            description: 'The name (as defined in the docker compose file) of the service to be restarted (usually one of "webserver" or "database"). If ommitted, all containers will be restarted.'
        );
        $this->addOption(
            'no-deps',
            description: 'Remove containers for services not defined in the Compose file.'
        );
    }
}

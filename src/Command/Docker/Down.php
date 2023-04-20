<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Command\Docker;

use Silverstripe\DevStarterKit\Command\BaseCommand;
use Silverstripe\DevStarterKit\Environment\HasEnvironment;
use Silverstripe\DevStarterKit\Environment\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Silverstripe\DevStarterKit\IO\StepLevel;
use Silverstripe\DevStarterKit\Environment\DockerService;
use Symfony\Component\Console\Input\InputOption;

/**
 * Effectively an environment-aware alias for "docker compose down"
 */
class Down extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:down';

    protected static $defaultDescription = 'Stops and removes docker containers, networks, and volumes';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Stopping and removing docker containers");

        $success = $this->getDockerService()->down(
            $this->input->getOption('remove-orphans'),
            $this->input->getOption('rmi'),
            $this->input->getOption('volumes'),
            DockerService::OUTPUT_TYPE_ALWAYS,
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return Command::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Docker containers stopped and removed');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['down']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Stops containers and removes containers, networks, volumes, and images created by docker:up.
        Basically an environment-aware alias for "docker compose down".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be stopped.',
            './'
        );
        $this->addOption(
            'remove-orphans',
            description: 'Remove containers for services not defined in the Compose file.'
        );
        $this->addOption(
            'rmi',
            description: "Remove 'local' images (only images that don't have a custom tag) used by services."
        );
        $this->addOption(
            'volumes',
            description: 'Remove named volumes declared in the volumes section of the Compose file and anonymous volumes attached to containers.'
        );
    }
}

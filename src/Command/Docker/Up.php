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
 * Effectively an environment-aware alias for "docker compose up -d"
 */
class Up extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:up';

    protected static $defaultDescription = 'Builds, (re)creates, starts, and attaches to docker containers.';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Creating and starting docker containers");

        $success = $this->getDockerService()->up(
            $this->input->getOption('build'),
            $this->input->getOption('no-build'),
            $this->input->getOption('force-recreate'),
            $this->input->getOption('no-recreate'),
            $this->input->getOption('remove-orphans'),
            DockerService::OUTPUT_TYPE_ALWAYS,
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return Command::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Docker containers created and started');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['up']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Unless they are already running, this command also starts any linked services.
        Basically an environment-aware alias for "docker compose up -d".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be started.',
            './'
        );
        $this->addOption(
            'build',
            description: 'Build images before starting containers.'
        );
        $this->addOption(
            'no-build',
            description: "Don't build an image, even if it's missing. Incompatible with --build"
        );
        $this->addOption(
            'force-recreate',
            description: "Recreate containers even if their configuration and image haven't changed."
        );
        $this->addOption(
            'no-recreate',
            description: "If containers already exist, don't recreate them. Incompatible with --force-recreate."
        );
        $this->addOption(
            'remove-orphans',
            description: 'Remove containers for services not defined in the Compose file.'
        );
    }
}

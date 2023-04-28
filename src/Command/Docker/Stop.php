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
 * Effectively an environment-aware alias for "docker compose stop"
 */
class Stop extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:stop';

    protected static $defaultDescription = 'Stops existing docker containers.';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Stopping docker containers");

        // Pull down docker
        $success = $this->getDockerService()->stop(DockerService::OUTPUT_TYPE_ALWAYS);
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return Command::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Docker containers Stopped');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['stop']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Basically an environment-aware alias for "docker compose stop".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be stopped.',
            './'
        );
    }
}

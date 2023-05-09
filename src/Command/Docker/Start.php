<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Docker;

use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Silverstripe\DevKit\IO\StepLevel;
use Silverstripe\DevKit\Environment\DockerService;
use Symfony\Component\Console\Input\InputOption;

/**
 * Effectively an environment-aware alias for "docker compose start"
 */
class Start extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'docker:start';

    protected static $defaultDescription = 'Starts existing docker containers.';

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Starting docker containers");

        // Pull down docker
        $success = $this->getDockerService()->start(DockerService::OUTPUT_TYPE_ALWAYS);
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Command failed', success: false);
            return self::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Docker containers started');
        return self::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['start']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Basically an environment-aware alias for "docker compose start".
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment whose containers will be started.',
            './'
        );
    }
}

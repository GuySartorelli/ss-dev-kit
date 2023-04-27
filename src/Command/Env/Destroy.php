<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Command\Env;

use Silverstripe\DevStarterKit\Command\BaseCommand;
use Silverstripe\DevStarterKit\Environment\HasEnvironment;
use Silverstripe\DevStarterKit\Environment\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use RuntimeException;
use Silverstripe\DevStarterKit\IO\StepLevel;
use Symfony\Component\Console\Input\InputOption;

/**
 * Code which destroys a dockerised local development environment
 */
class Destroy extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'env:destroy';

    protected static $defaultDescription = 'Completely tears down an environment that was created with the "create" command.';

    private Filesystem $filesystem;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        // Confirm teardown if the user didn't specify the environment to avoid human error tearing down the wrong env.
        if ($input->getArgument('env-path') === './') {
            $continue = $this->output->ask(
                'You passed no arguments and are tearing down <options=bold>' . $this->env->getName() . '</> - do you wish to continue?',
                default: 'y'
            );
            if (!is_string($continue) || !preg_match('/^y(es)?$/i', $continue)) {
                throw new RuntimeException('Opting not to tear down this environment.');
            }
        }
        $this->filesystem = new Filesystem();
    }

    protected function rollback(): void
    {
        //no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, "Destroying environment at <info>{$this->env->getProjectRoot()}</info>");

        // Make sure we're not _in_ the environment dir when we destroy it.
        if (Path::isBasePath($this->env->getProjectRoot(), getcwd())) {
            chdir(Path::join($this->env->getProjectRoot(), '../'));
        }

        // Pull down docker
        $success = $this->pullDownDocker();
        if (!$success) {
            $this->output->endStep(StepLevel::Command, success: false);
            return Command::FAILURE;
        }

        if ($this->input->getOption('detach')) {
            // Delete environment-specific directories
            // @TODO also clean up .env and other stuff we shoved in the webroot
            $this->output->writeln('Deleting devkit-specific directories');
            try {
                $this->filesystem->remove($this->env->getDockerDir());
                $this->filesystem->remove($this->env->getMetaDir());
            } catch (IOException $e) {
                $this->output->endStep(StepLevel::Command, "Couldn't delete directory: {$e->getMessage()}", false);
                return Command::FAILURE;
            }
        } else {
            // Delete environment directory
                $this->output->writeln('Removing environment directory');
            try {
                $this->filesystem->remove($this->env->getProjectRoot());
            } catch (IOException $e) {
                $this->output->endStep(StepLevel::Command, "Couldn't delete environment directory: {$e->getMessage()}", false);
                return Command::FAILURE;
            }
        }

        $destroyedOrDetached = $this->input->getOption('detach') ? 'detached' : 'destroyed';
        $this->output->endStep(StepLevel::Command, "Environment successfully $destroyedOrDetached.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Taking down docker');

        $success = $this->getDockerService()->down(removeOrphans: true, images: true, volumes: true);
        if (!$success) {
            $this->output->endStep(StepLevel::Primary, 'Problem occured while stopping docker containers.', false);
            return false;
        }

        $this->output->endStep(StepLevel::Primary, 'Took down docker successfully');
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->filesystem = new Filesystem();

        $this->setAliases(['destroy']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Removes a project by pulling down the docker containers and volumes etc and
        deleting the project's directory.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
        $this->addOption(
            'detach',
            'd',
            InputOption::VALUE_NEGATABLE,
            'detach the docker environment but do not remove the project directory.',
            false,
        );
    }
}

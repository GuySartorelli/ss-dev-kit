<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Command\Env;

use Silverstripe\DevStarterKit\Command\BaseCommand;
use Silverstripe\DevStarterKit\Trait\HasEnvironment;
use Silverstripe\DevStarterKit\Trait\UsesDocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use RuntimeException;

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
            $continue = $this->io->ask('You passed no arguments and are tearing down <options=bold>' . $this->env->getName() . '</> - do you wish to continue?');
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
        // Make sure we're not _in_ the environment dir when we destroy it.
        if (Path::isBasePath($this->env->getProjectRoot(), getcwd())) {
            chdir(Path::join($this->env->getProjectRoot(), '../'));
        }

        // Pull down docker
        $failureCode = $this->pullDownDocker();
        if ($failureCode) {
            return $failureCode;
        }

        // Delete environment directory
        try {
            $this->io->writeln(self::STYLE_STEP . 'Removing environment directory' . self::STYLE_CLOSE);
            $this->filesystem->remove($this->env->getProjectRoot());
        } catch (IOException $e) {
            $this->io->error('Couldn\'t delete environment directory: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->io->success("Env {$this->env->getName()} successfully destroyed.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): int|bool
    {
        $this->io->writeln(self::STYLE_STEP . 'Taking down docker' . self::STYLE_CLOSE);

        $success = $this->getDockerService()->down(true, true);
        if (!$success) {
            $this->io->error('Problem occured while stopping docker containers.');
            return Command::FAILURE;
        }

        return false;
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
    }
}

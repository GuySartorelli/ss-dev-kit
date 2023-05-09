<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Database;

use RuntimeException;
use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Compat\Filesystem;
use Silverstripe\DevKit\Environment\DockerService;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Silverstripe\DevKit\IO\StepLevel;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Command which dumps a database to some file in the host
 */
class Dump extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    private string $dumpDir;

    protected static $defaultName = 'database:dump';

    protected static $defaultDescription = 'Dump the database to a file.';

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->validateOptions();
    }

    private function validateOptions()
    {
        $this->input->validate();

        $fileSystem = new Filesystem();
        $this->dumpDir = Path::canonicalize($this->input->getArgument('destination-dir'));
        if (!Path::isAbsolute($this->dumpDir)) {
            $this->dumpDir = Path::makeAbsolute($this->dumpDir, getcwd());
        }

        if (!$fileSystem->exists($this->dumpDir)) {
            throw new RuntimeException("destination-dir '$this->dumpDir' does not exist.");
        }

        if ($fileSystem->isFile($this->dumpDir)) {
            throw new RuntimeException("destination-dir must not be a file.");
        }

        if (str_contains($this->dumpDir, ':') || str_contains($this->input->getArgument('filename') ?: '', ':')) {
            throw new RuntimeException('Neither "destination-dir" nor "filename" can contain a colon.');
        }
    }

    protected function rollback(): void
    {
        // no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->output->startStep(StepLevel::Command, 'Dumping database.');

        $filename = $this->input->getArgument('filename') ?: $this->env->getName() . '.' . date('Y-m-d\THis');
        $tmpFilePath = "/tmp/$filename.sql.gz";

        $success = $this->getDockerService()->exec(
            "mysqldump -u root --password=root SS_mysite | gzip > $tmpFilePath",
            container: DockerService::CONTAINER_DATABASE,
            outputType: DockerService::OUTPUT_TYPE_DEBUG
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, success: false);
            return self::FAILURE;
        }

        $this->output->writeln('Copying database to host.');
        $success = $this->getDockerService()->copyFromContainer(
            DockerService::CONTAINER_DATABASE,
            $tmpFilePath,
            Path::join($this->dumpDir, $filename . '.sql.gz')
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Problem occured while copying file from docker container.', success: false);
            return self::FAILURE;
        }

        $this->output->writeln('Cleaning up inside container.');
        $success = $this->getDockerService()->exec(
            "rm $tmpFilePath",
            container: DockerService::CONTAINER_DATABASE,
            outputType: DockerService::OUTPUT_TYPE_DEBUG
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, success: false);
            return self::FAILURE;
        }

        $this->output->endStep(StepLevel::Command, 'Database dumped successfully.');
        return self::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['dump']);

        $this->addArgument(
            'destination-dir',
            InputArgument::REQUIRED,
            'The path for a directory where the database should be dumped to.',
        );
        $this->addArgument(
            'filename',
            InputArgument::OPTIONAL,
            'The name for the dumped database file (minus extension). Default is the project name and the current datetime.',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to dump.',
            './'
        );
    }
}

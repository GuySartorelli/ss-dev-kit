<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Database;

use LogicException;
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
 * Command which restores a database from some file in the host
 */
class Restore extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    private string $sourceFile;

    protected static $defaultName = 'database:restore';

    protected static $defaultDescription = 'Restore the database from a file.';

    public const VALID_FILE_TYPES = [
        '.sql.zip',
        '.sql.tar.gz',
        '.sql.tgz',
        '.sql.tar',
        '.sql.gz',
        '.sql.bz2',
        '.sql',
    ];

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
        $this->sourceFile = Path::canonicalize($this->input->getArgument('source-file'));
        if (!Path::isAbsolute($this->sourceFile)) {
            $this->sourceFile = Path::makeAbsolute($this->sourceFile, getcwd());
        }

        if (!$fileSystem->exists($this->sourceFile)) {
            throw new RuntimeException("source-file '$this->sourceFile' does not exist.");
        }

        if (!$fileSystem->isFile($this->sourceFile)) {
            throw new RuntimeException("source-file must be a file.");
        }

        if (str_contains($this->sourceFile, ':')) {
            throw new RuntimeException('source-file cannot contain a colon.');
        }

        $validExt = false;
        foreach (self::VALID_FILE_TYPES as $ext) {
            if (!str_ends_with($this->sourceFile, $ext)) {
                $validExt = true;
                break;
            }
        }
        if (!$validExt) {
            throw new RuntimeException('source-file filetype must be one of ' . implode(', ', self::VALID_FILE_TYPES));
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
        $this->output->startStep(StepLevel::Command, 'Restoring database.');

        $filename = basename($this->sourceFile);
        $tmpFilePath = "/tmp/$filename";

        $this->output->writeln('Copying database to container.');
        $success = $this->getDockerService()->copyToContainer(
            DockerService::CONTAINER_DATABASE,
            $this->sourceFile,
            $tmpFilePath
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, 'Problem occured while copying file to docker container.', success: false);
            return self::FAILURE;
        }

        $this->output->writeln('Restoring database from file.');
        $success = $this->getDockerService()->exec(
            $this->getRestoreCommand($tmpFilePath),
            container: DockerService::CONTAINER_DATABASE,
            outputType: DockerService::OUTPUT_TYPE_DEBUG
        );
        if (!$success) {
            $this->output->endStep(StepLevel::Command, success: false);
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

        $this->output->endStep(StepLevel::Command, 'Database restored successfully.');
        return self::SUCCESS;
    }

    private function getRestoreCommand(string $filePath): string
    {
        $mysqlPart = 'mysql -u root --password=root SS_mysite';
        foreach (self::VALID_FILE_TYPES as $ext) {
            if (str_ends_with($this->sourceFile, $ext)) {
                switch ($ext) {
                    case '.sql.zip':
                        return "unzip -p $filePath | $mysqlPart";
                    case '.sql.tar.gz':
                    case '.sql.tgz':
                        return "tar -O -xzf $filePath | $mysqlPart";
                    case '.sql.tar':
                        return "tar -O -xf $filePath | $mysqlPart";
                    case '.sql.gz':
                        return "zcat $filePath | $mysqlPart";
                    case '.sql.bz2':
                        return "bunzip2 < $filePath | $mysqlPart";
                    case '.sql':
                        return "cat $filePath | $mysqlPart";
                    default:
                        throw new LogicException("Unexpected file extension $ext");
                }
            }
        }
        throw new LogicException('source-file has an unexpected file extension');
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['restore']);

        $this->addArgument(
            'source-file',
            InputArgument::REQUIRED,
            'The path to the file from which the database will be restored. Valid filetypes are ' . implode(', ', self::VALID_FILE_TYPES),
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to restore.',
            './'
        );
    }
}

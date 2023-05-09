<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Infrastructure;

use RuntimeException;
use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\DockerService;
use Silverstripe\DevKit\Environment\PHPService;
use Silverstripe\DevKit\IO\StepLevel;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PhpConfig extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'infrastructure:phpconfig';

    protected static $defaultDescription = 'Make changes to PHP config (e.g. change php version, toggle xdebug).';

    private PHPService $phpService;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // @TODO use a validate method instead?
        $hasOne = false;
        foreach (['php-version', 'info', 'toggle-debug'] as $option) {
            if ($input->getOption($option)) {
                $hasOne = true;
                break;
            }
        }
        if (!$hasOne) {
            throw new RuntimeException('At least one option must be used.');
        }
        parent::initialize($input, $output);
        $this->phpService = new PHPService($this->env, $this->output);
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
        $this->output->startStep(StepLevel::Command, '');

        // Swap PHP version first - the other options will be based on the new PHP version
        if ($phpVersion = $this->input->getOption('php-version')) {
            $success = $this->phpService->swapToVersion($phpVersion);
            if (!$success) {
                return self::FAILURE;
            }
        }

        if ($this->input->getOption('toggle-debug')) {
            $success = $this->toggleDebug();
            if (!$success) {
                return self::FAILURE;
            }
        }

        if ($this->input->getOption('info')) {
            $success = $this->printPhpInfo();
            if (!$success) {
                return self::FAILURE;
            }
        }

        $this->output->endStep(StepLevel::Command, 'Sucessfully completed command');
        return self::SUCCESS;
    }

    protected function toggleDebug(): bool
    {
        $value = 'zend_extension=xdebug.so';
        $version = $this->phpService->getCliPhpVersion();
        $onOff = 'on';
        if ($this->phpService->debugIsEnabled($version)) {
            $onOff = 'off';
            $value = ';' . $value;
        }

        $this->output->writeln("Turning debug $onOff");

        $path = $this->phpService->getDebugPath($version);
        $command = "echo \"$value\" > \"{$path}\" && /etc/init.d/apache2 reload";

        return $this->getDockerService()->exec($command, asRoot: true, outputType: DockerService::OUTPUT_TYPE_DEBUG);
    }

    protected function printPhpInfo(): bool
    {
        $this->output->writeln('Printing PHP info');
        return $this->getDockerService()->exec('php -i', outputType: DockerService::OUTPUT_TYPE_ALWAYS);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['phpconfig']);

        $this->setHelp(<<<HELP
        This command is for setting new configuration, with the exception of the <info>--info</info> option.
        To get information about the current configuration, use the <info>env:details</info> command.
        HELP);
        $this->addOption(
            'php-version',
            'P',
            InputOption::VALUE_OPTIONAL,
            'Swap to a specific PHP version.',
        );
        $this->addOption(
            'toggle-debug',
            'd',
            InputOption::VALUE_NONE,
            'Toggle xdebug on/off.',
        );
        $this->addOption(
            'info',
            'i',
            InputOption::VALUE_NONE,
            'Print out phpinfo (for webserver - assumed same for cli).',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The full path to the directory of the environment.',
            './'
        );
    }
}

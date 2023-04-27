<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Tests\IO\CommandOutputTest;

use Silverstripe\DevStarterKit\Command\BaseCommand;
use Silverstripe\DevStarterKit\IO\StepLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command which creates a dockerised local development environment
 */
class TestCommand extends BaseCommand
{
    protected static $defaultName = 'test:output';

    public const BEGIN_COMMAND = 'begin command';
    public const END_COMMAND = 'end command';
    public const BEGIN_PRIMARY = 'begin primary';
    public const END_PRIMARY = 'end primary';
    public const BEGIN_SECONDARY = 'begin secondary';
    public const END_SECONDARY = 'end secondary';
    public const BEGIN_TERTIARY = 'begin tertiary';
    public const END_TERTIARY = 'end tertiary';

    protected function doExecute(): int
    {
        $num = 3;

        $this->output->writeln('This is some temporary output BEFORE the command');

        for ($i = 0; $i < $num; $i++) {
            $this->output->writeln('Output: '. $i);
            sleep(1);
        }


        $this->output->startStep(StepLevel::Command, self::BEGIN_COMMAND);
        $this->output->writeln('This is some temporary output within the command');

        for ($i = 0; $i < $num; $i++) {
            $this->output->writeln('Output: '. $i);
            sleep(1);
        }

        $this->output->startStep(StepLevel::Primary, self::BEGIN_PRIMARY);
        $this->output->writeln([
            'This is some temporary output within the primary step',
            'And some more output',
        ]);

        for ($i = 0; $i < $num; $i++) {
            $this->output->writeln('Output: '. $i);
            sleep(1);
        }

        $this->output->startStep(StepLevel::Secondary, self::BEGIN_SECONDARY);
        $this->output->writeln('This is some temporary output within the secondary step');

        for ($i = 0; $i < $num; $i++) {
            $this->output->writeln('Output: '. $i);
            sleep(1);
        }

        $this->output->startStep(StepLevel::Tertiary, self::BEGIN_TERTIARY);
        $this->output->writeln('This is some temporary output within the tertiary step');

        $this->output->writeln('This will output regardless of step level', OutputInterface::VERBOSITY_NORMAL);

        for ($i = 0; $i < $num; $i++) {
            $this->output->writeln('Output: '. $i);
            sleep(1);
        }

        $this->output->endStep(StepLevel::Tertiary, self::END_TERTIARY);

        $this->output->endStep(StepLevel::Secondary, self::END_SECONDARY);

        $this->output->startStep(StepLevel::Secondary, self::BEGIN_SECONDARY);
        $this->output->writeln('This is some temporary output within anotehr secondary step');

        for ($i = 0; $i < $num; $i++) {
            sleep(1);
            $this->output->writeln('Output: '. $i);
        }

        $this->output->endStep(StepLevel::Secondary, self::END_SECONDARY);


        $this->output->endStep(StepLevel::Primary, self::END_PRIMARY);

        $this->output->endStep(StepLevel::Command, self::END_COMMAND);
        return Command::SUCCESS;
    }

    protected function rollback(): void { /* no-op */ }
}

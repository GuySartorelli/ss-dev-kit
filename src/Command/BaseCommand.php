<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Command;

use Exception;
use Silverstripe\DevStarterKit\Trait\HasEnvironment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    protected bool $isSubCommand = false;

    public const STYLE_STEP = '<fg=blue>';

    public const STYLE_CLOSE = '</>';

    protected InputInterface $input;

    protected OutputInterface $output;

    protected SymfonyStyle $io;

    /**
     * Executes the current command.
     * DO NOT override the execute() method directly.
     *
     * @return integer
     */
    abstract protected function doExecute(): int;

    /**
     * Rollback the action if there was a failure.
     * If there is no rollback necessary, add a no-op comment.
     *
     * @return int one of the Command constants
     */
    abstract protected function rollback(): void;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);

        if (in_array(HasEnvironment::class, class_uses($this))) {
            /** @var BaseCommand&HasEnvironment $this */
            $this->initiateEnv();
        }
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute();
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}

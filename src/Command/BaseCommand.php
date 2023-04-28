<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command;

use Exception;
use Silverstripe\DevKit\IO\CommandOutput;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Silverstripe
 */
abstract class BaseCommand extends Command
{
    protected bool $isSubCommand = false;

    protected InputInterface $input;

    protected CommandOutput $output;

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
        parent::initialize($input, $output);
        $this->input = $input;
        $this->output = new CommandOutput($input, $output);

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

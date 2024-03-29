<?php declare(strict_types=1);

namespace Silverstripe\DevKit\IO;

use LogicException;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wraps the default output in a layer that is aware of command steps and provides appropriate progress bars depending on the current verbosity level.
 */
class CommandOutput implements OutputInterface
{
    public const STYLE_CLOSE = '</>';

    private SymfonyStyle $io;

    private StepLevel $stepLevel = StepLevel::None;

    private ?ProgressBar $progressBar = null;

    public function __construct(InputInterface $input, OutputInterface $originalOutput)
    {
        $this->io = new SymfonyStyle($input, $originalOutput);
    }

    /**
     * Start a step, outputting a message.
     *
     * Different step levels output at different verbosities.
     * @see StepLevel::getVerbosity()
     */
    public function startStep(StepLevel $stepLevel, string $message): void
    {
        if ($stepLevel === StepLevel::None) {
            throw new LogicException('Cannot start "None" step.');
        }
        if ($this->stepLevel->value !== $stepLevel->previous()->value) {
            $needLevel = strtolower($stepLevel->previous()->name);
            $badLevel = strtolower($stepLevel->name);
            throw new LogicException("Must be on a $needLevel step to start a $badLevel step.");
        }

        $this->tryEndProgressBar($this->stepLevel);

        if ($this->stepWillOutput()) {
            $this->stepMessage($stepLevel, $message);
        } else {
            $this->advanceProgressBar($message);
        }

        $this->stepLevel = $stepLevel;
    }

    /**
     * End a step, outputting a message.
     *
     * @param bool $success Whether the step was successful or not.
     */
    public function endStep(StepLevel $stepLevel, string $message = '', bool $success = true)
    {
        if ($stepLevel === StepLevel::None) {
            throw new LogicException('Cannot end "None" step.');
        }
        if ($stepLevel->isGreaterThan($this->stepLevel)) {
            throw new LogicException('Cannot end step level we haven\'t started yet.');
        }

        $newStepLevel = $stepLevel->previous();

        $this->tryEndProgressBar($newStepLevel);

        if ($success) {
            if ($stepLevel === StepLevel::Command) {
                $this->io->success($message);
            } elseif ($this->stepWillOutput($newStepLevel)) {
                $this->stepMessage($stepLevel, $message);
            } else {
                $this->advanceProgressBar($message);
            }
        } elseif ($message) {
            $this->error($message);
        }

        $this->stepLevel = $newStepLevel;
    }

    public function getCurrentStep(): StepLevel
    {
        return $this->stepLevel;
    }

    /**
     * Returns true if the step level will output given the current verbosity level.
     *
     * If no step level is passed in, the current step level will be used.
     */
    public function stepWillOutput(?StepLevel $stepLevel = null): bool
    {
        if ($stepLevel === null) {
            $stepLevel = $this->stepLevel;
        }
        return $stepLevel->outputInVerbosity($this->getVerbosity());
    }

    /**
     * Outputs the start or end message for this step with the appropriate styling
     */
    private function stepMessage(StepLevel $stepLevel, string $message): void
    {
        if (!$message) {
            return;
        }
        $this->io->writeln($stepLevel->getStyle() . $message . self::STYLE_CLOSE);
    }

    /**
     * @inheritDoc
     */
    public function write(
        string|iterable $messages,
        bool $newline = false,
        int $options = 0
    ): void
    {
        $verbosity = $this->getVerbosityForOutput($options);
        if ($this->io->getVerbosity() >= $verbosity) {
            $this->clearProgressBar();
            if (!$options && $this->stepLevel !== StepLevel::None) {
                $messages = $this->formatAsStepLevel($messages, $this->stepLevel->next());
            }
            $this->io->write($messages, $newline, $verbosity);
        } else {
            $this->advanceProgressBar();
        }
    }

    /**
     * @inheritDoc
     */
    public function writeln(string|iterable $messages, int $options = 0): void
    {
        $verbosity = $this->getVerbosityForOutput($options);
        if ($this->io->getVerbosity() >= $verbosity) {
            $this->clearProgressBar();
            if (!$options && $this->stepLevel !== StepLevel::None) {
                $messages = $this->formatAsStepLevel($messages, $this->stepLevel->next());
            }
            $this->io->writeln($messages, $verbosity);
        } else {
            $this->advanceProgressBar();
        }
    }

    /**
     * Formats a message as a block of text.
     *
     * @param bool $verbosity Output this block in a specific verbosity, bypassing step levels.
     * Should be one of the VERBOSITY constants.
     */
    public function block(
        string|array $messages,
        string $type = null,
        string $style = null,
        string $prefix = ' ',
        bool $padding = false,
        bool $escape = true,
        int $verbosity = 0
    ): void
    {
        $verbosity = $this->getVerbosityForOutput($verbosity);
        if ($this->getVerbosity() >= $verbosity) {
            $this->clearProgressBar();
            if (!$verbosity && $this->stepLevel !== StepLevel::None) {
                $messages = $this->formatAsStepLevel($messages, $this->stepLevel->next());
            }
            $this->io->block($messages, $type, $style, $prefix, $padding, $escape);
        } else {
            $this->advanceProgressBar();
        }
    }

    /**
     * A shortcut for {@link block()} formatted as a warning.
     */
    public function warningBlock(string|array $messages, bool $escape = true): void
    {
        $this->block(
            $messages,
            'WARNING',
            'fg=black;bg=yellow',
            padding: true,
            escape: $escape,
            verbosity: OutputInterface::VERBOSITY_NORMAL
        );
    }

    /**
     * Formats a horizontal table.
     *
     * @param bool $verbosity Output this block in a specific verbosity, bypassing step levels.
     * Should be one of the VERBOSITY constants.
     */
    public function horizontalTable(array $headers, array $rows, int $verbosity = 0): void
    {
        $verbosity = $this->getVerbosityForOutput($verbosity);
        if ($this->getVerbosity() >= $verbosity) {
            $this->clearProgressBar();
            $this->io->horizontalTable($headers, $rows);
        } else {
            $this->advanceProgressBar();
        }
    }

    /**
     * Format some output in the style of a given step level
     */
    private function formatAsStepLevel(string|iterable $messages, ?StepLevel $stepLevel)
    {
        if (!$stepLevel) {
            return $messages;
        }

        if (is_string($messages)) {
            return $stepLevel->getStyle() . $messages . self::STYLE_CLOSE;
        }

        if (!is_array($messages)) {
            $messages = iterator_to_array($messages);
        }
        $firstKey = array_key_first($messages);
        $lastKey = array_key_last($messages);
        $messages[$firstKey] = $stepLevel->getStyle() . $messages[$firstKey];
        $messages[$lastKey] .= self::STYLE_CLOSE;
        return $messages;
    }

    /**
     * Output an error message to the terminal
     *
     * Outputs regardless of step level and verbosity
     */
    public function error(string|array $message): void
    {
        $this->clearProgressBar();
        $this->io->error($message);
    }

    /**
     * Output a warning message to the terminal
     *
     * Outputs regardless of step level and verbosity
     */
    public function warning(string|array $message): void
    {
        $this->clearProgressBar();
        $this->io->warning($message);
    }

    /**
     * Prompts for and returns user input
     *
     * Outputs regardless of step level and verbosity
     */
    public function ask(string $question, string $default = null, callable $validator = null): mixed
    {
        $this->clearProgressBar();
        return $this->io->ask($question, $default, $validator);
    }

    /**
     * @inheritDoc
     */
    public function setVerbosity(int $level): void
    {
        $this->io->setVerbosity($level);
    }

    /**
     * @inheritDoc
     */
    public function getVerbosity(): int
    {
        return $this->io->getVerbosity();
    }

    /**
     * @inheritDoc
     */
    public function isQuiet(): bool
    {
        return $this->io->isQuiet();
    }

    /**
     * @inheritDoc
     */
    public function isVerbose(): bool
    {
        return $this->io->isVerbose();
    }

    /**
     * @inheritDoc
     */
    public function isVeryVerbose(): bool
    {
        return $this->io->isVeryVerbose();
    }

    /**
     * @inheritDoc
     */
    public function isDebug(): bool
    {
        return $this->io->isDebug();
    }

    /**
     * @inheritDoc
     */
    public function setDecorated(bool $decorated)
    {
        $this->io->setDecorated($decorated);
    }

    /**
     * @inheritDoc
     */
    public function isDecorated(): bool
    {
        return $this->io->isDecorated();
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $this->io->setFormatter($formatter);
    }

    /**
     * @inheritDoc
     */
    public function getFormatter(): OutputFormatterInterface
    {
        return $this->io->getFormatter();
    }

    /**
     * Advances the current progress bar, starting a new one if necessary.
     */
    public function advanceProgressBar(?string $message = null): void
    {
        if ($this->progressBar === null) {
            $this->progressBar = $this->io->createProgressBar();
            $this->progressBar->setFormat('%elapsed:10s% %bar% %message%');
            $this->progressBar->setBarWidth(10);
            $this->progressBar->setMessage('');
        }
        $this->progressBar->display();

        if ($message !== null) {
            $this->progressBar->setMessage($message);
        }

        $this->progressBar->advance();
    }

    /**
     * Clears the current progress bar (if any) from the console.
     *
     * Useful if we need to output something directly while a progress bar may be running.
     */
    public function clearProgressBar(): void
    {
        $this->progressBar?->clear();
    }

    /**
     * Clears and unsets the progressbar, but ONLY if we're swapping off a step that had visible output.
     */
    private function tryEndProgressBar(StepLevel $stepLevel): void
    {
        if ($this->progressBar !== null && $this->stepWillOutput($stepLevel)) {
            $this->progressBar->finish();
            $this->progressBar->clear();
            $this->progressBar = null;
        }
    }

    /**
     * Get the verbosity to use for output.
     *
     * If a verbosity is passed in, that verbosity is used. Otherwise, current step level's
     * verbosity is used.
     */
    private function getVerbosityForOutput(int $options): int
    {
        // use bitwise operations to separate verbosities from output modes
        $verbosities = self::VERBOSITY_QUIET | self::VERBOSITY_NORMAL | self::VERBOSITY_VERBOSE | self::VERBOSITY_VERY_VERBOSE | self::VERBOSITY_DEBUG;
        $verbosity = $verbosities & $options;
        // return the given verbosity, if any
        if ($verbosity) {
            return $verbosity;
        }

        return $this->stepLevel->getVerbosity();
    }
}

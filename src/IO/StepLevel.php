<?php declare(strict_types=1);

namespace Silverstripe\DevKit\IO;

use Symfony\Component\Console\Output\OutputInterface;
use LogicException;

enum StepLevel: int
{
    /**
     * For internal use only
     */
    case None = 0;

    /**
     * The command execution as a whole.
     *
     * Command::doExecute() should start and end with this step level.
     */
    case Command = 1;

    /**
     * Each step of command execution.
     * Output within a primary step is hidden in normal verbosity.
     *
     * Normally methods called directly from doExecute() are primary steps.
     */
    case Primary = 2;

    /**
     * Larger sub-steps that have a lot to them.
     * Output within secondary steps are only shown in very verbose mode.
     *
     * Normally methods called from within primary steps.
     */
    case Secondary = 3;

    /**
     * Big chunks of mostly unnecessary output.
     * Output within tertiary steps are only shown in debug mode.
     *
     * Reserved for large output such as from composer or sake in the create command.
     */
    case Tertiary = 4;

    /**
     * Returns true if this step's start and end messages should output to the terminal in a given verbosity level
     *
     * @param int $verbosity One of the VERBOSITY constants on OutputInterface
     */
    public function outputInVerbosity(int $verbosity): bool
    {
        return $verbosity >= $this->getVerbosity();
    }

    /**
     * Get the verbosity at which this step's start and end messages will output to the terminal
     */
    public function getVerbosity(): int
    {
        switch ($this) {
            case self::None:
            case self::Command:
                return OutputInterface::VERBOSITY_NORMAL;
            case self::Primary:
                return OutputInterface::VERBOSITY_VERBOSE;
            case self::Secondary:
                return OutputInterface::VERBOSITY_VERY_VERBOSE;
            case self::Tertiary:
                return OutputInterface::VERBOSITY_DEBUG;
            default:
                throw new LogicException("Verbosity has not been defined for step level '$this->name'");
        }
    }

    public function isLessThan(StepLevel $other): bool
    {
        return $this->value < $other->value;
    }

    public function isGreaterThan(StepLevel $other): bool
    {
        return $this->value > $other->value;
    }

    public function previous(): ?static
    {
        return static::tryFrom($this->value - 1);
    }

    public function next(): ?static
    {
        return static::tryFrom($this->value + 1);
    }

    /**
     * Get the style used for direct step output
     */
    public function getStyle(): string
    {
        switch ($this) {
            case self::None:
                throw new LogicException('Step level "None" should never have output');
            case self::Command:
                return '<fg=green>';
            case self::Primary:
                return '<fg=cyan>';
            case self::Secondary:
                return '<fg=blue>';
            case self::Tertiary:
                // Just default output styling
                return '<fg=gray>';
            default:
                throw new LogicException("Style has not been defined for step level '$this->name'");
        }
    }
}

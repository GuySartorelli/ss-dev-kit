<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Environment;

use LogicException;
use Symfony\Component\Filesystem\Path;

/**
 * Use this trait on any commands which can or must have a valid environment to function.
 */
trait HasEnvironment
{
    /**
     * If true, we don't throw exceptions on initialize if the working directory isn't in an env.
     */
    protected bool $environmentOptional = false;

    protected Environment $env;

    protected function initiateEnv()
    {
        $proposedPath = '';
        if ($this->input->hasArgument('env-path')) {
            $proposedPath = $this->input->getArgument('env-path');
        } elseif ($this->input->hasOption('env-path')) {
            $proposedPath = $this->input->getOption('env-path');
        }

        if (!$proposedPath && !$this->environmentOptional) {
            throw new LogicException('No environment path available.');
        }

        if ($proposedPath) {
            $this->env = new Environment(
                Path::makeAbsolute(Path::canonicalize($proposedPath), getcwd()),
                allowMissing: $this->environmentOptional
            );
        }
    }
}

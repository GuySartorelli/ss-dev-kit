<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit;

use Silverstripe\DevStarterKit\Command\BaseCommand;

class TempCommand extends BaseCommand
{
    protected static $defaultName = 'configtest';

    protected function doExecute(): int
    {
        return self::SUCCESS;
    }

    protected function rollback(): void
    { }
}

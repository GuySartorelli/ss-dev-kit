<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Path;

class Application extends ConsoleApplication
{
    public static function bootstrap(): void
    {
        set_time_limit(0);
        // @TODO we probably want to implement an error handler
        // If we emit all errors as ErrorException, we can then add one big try/catch
        // and then we can make sure all uncaught exceptions render in a way we want.
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        // @TODO we may want a debug mode which, when enabled, doesn't set these.
        if (extension_loaded('xdebug')) {
            ini_set('xdebug.show_exception_trace', '0');
            ini_set('xdebug.scream', '0');
        }

        $rootDir = self::getRootDir();
        // Boot environment variables
        $envConfig = new Dotenv();
        $envConfig->usePutenv(true);
        $envConfig->bootEnv(Path::join($rootDir, '.env'));
    }

    /**
     * Get the base directory of the dev tools
     */
    public static function getRootDir(): string
    {
        return Path::canonicalize(Path::join(__DIR__, '..'));
    }

    /**
     * Get the directory where templates live
     */
    public static function getTemplateDir(): string
    {
        return Path::join(self::getRootDir(), 'templates');
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public static function getCopyDir(): string
    {
        return Path::join(self::getRootDir(), 'copy-to-environment');
    }
}

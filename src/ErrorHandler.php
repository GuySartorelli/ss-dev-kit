<?php declare(strict_types=1);

namespace Silverstripe\DevKit;

use ErrorException;
use Silverstripe\DevKit\IO\CommandOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ErrorHandler
{
    private static CommandOutput $output;

    /**
     * Error handler
     *
     * @param int    $level   Level of the error raised
     * @param string $message Error message
     * @param string $file    Filename that the error was raised in
     * @param int    $line    Line number the error was raised at
     *
     * @static
     * @throws \ErrorException
     */
    public static function handle(int $level, string $message, string $file, int $line): bool
    {
        $isDeprecationNotice = $level === E_DEPRECATED || $level === E_USER_DEPRECATED;

        // error code is not included in error_reporting levels
        if (!$isDeprecationNotice && !(error_reporting() & $level)) {
            return true;
        }

        if (filter_var(ini_get('xdebug.scream'), FILTER_VALIDATE_BOOLEAN)) {
            $message .= "\n\nWarning: You have xdebug.scream enabled, the warning above may be".
            "\na legitimately suppressed error that you were not supposed to see.";
        }

        // Handle errors/warnings/etc appropriately
        switch ($level) {
            case E_NOTICE:
            case E_USER_NOTICE:
                self::$output->writeln('NOTICE: ' . $message, OutputInterface::VERBOSITY_VERY_VERBOSE);
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $message = 'Deprecation Notice: ' . $message;
                // then just output the same as a warning, so no break
            case E_WARNING:
            case E_USER_WARNING:
                if (self::$output->isDebug()) {
                    self::$output->warningBlock([
                        $message . ' in ' . $file . ':' . $line,
                        'Stack trace:',
                        self::getStackTrace(),
                    ]);
                } else {
                    self::$output->warning($message);
                }
                break;
            case E_ERROR:
            case E_USER_ERROR:
            default:
                throw new ErrorException($message, 0, $level, $file, $line);
        }

        return true;
    }

    private static function getStackTrace()
    {
        // Remove ErrorHandler methods from trace
        $stack = array_slice(debug_backtrace(), 3);

        // Include all items which indicate the line and file
        // for now, ignore args
        return array_filter(
            array_map(static function ($trace): ?string {
                if (isset($trace['line'], $trace['file'])) {
                    return $trace['file'] . ':' . $trace['line'];
                }
                return null;
            }, $stack)
        );
    }

    /**
     * Register error handler.
     */
    public static function register(CommandOutput $output): void
    {
        set_error_handler([__CLASS__, 'handle']);
        error_reporting(E_ALL | E_STRICT);
        self::$output = $output;
    }
}

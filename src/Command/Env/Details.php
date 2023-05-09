<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Env;

use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\DockerService;
use Silverstripe\DevKit\Environment\PHPService;
use Silverstripe\DevKit\Environment\HasEnvironment;
use Silverstripe\DevKit\Environment\UsesDocker;
use Symfony\Component\Console\Input\InputArgument;

class Details extends BaseCommand
{
    use HasEnvironment, UsesDocker;

    protected static $defaultName = 'env:details';

    protected static $defaultDescription = 'Get information about settings and container status in a dev environment.';

    protected function rollback(): void
    {
        // no-op
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $phpService = new PHPService($this->env, $this->output);
        $containers = $this->getDockerService()->getContainersStatus();
        $canCheckContainer = $containers['webserver'] === 'running';
        $baseURL = $this->env->getBaseURL();
        list ($dbDriver, $dbVersion) = $this->getDbData($containers['database']);

        $this->output->horizontalTable([
            'URL',
            'CMS URL',
            'Mailhog',
            'DB driver',
            'DB version',
            'XDebug',
            'CLI PHP Version',
            'Apache PHP Version',
            'Available PHP Versions',
            ...array_map(fn($containerName) => "$containerName container", array_keys($containers)),
        ], [[
            "<href={$baseURL}/>{$baseURL}/</>",
            "<href={$baseURL}/admin>{$baseURL}/admin</>",
            'TODO', // @TODO get proxied route for mailhogs
            $dbDriver,
            $dbVersion,
            $canCheckContainer ? ($phpService->debugIsEnabled() ? 'On' : 'Off') : null,
            $canCheckContainer ? $phpService->getCliPhpVersion() : null,
            $canCheckContainer ? $phpService->getApachePhpVersion() : null,
            implode(', ', PHPService::getAvailableVersions()),
            ...array_values($containers),
        ]]);

        return self::SUCCESS;
    }

    private function getDbData(string $containerStatus): array
    {
        $staticData = $this->env->getDockerComposeData()['services']['database']['image'] ?? 'unknown:unknown';
        $data = explode(':', $staticData);
        $driver = $data[0];
        if ($driver !== 'unknown' && $containerStatus === 'running') {
            $version = $this->getDockerService()->exec(
                "$driver --version",
                container: DockerService::CONTAINER_DATABASE,
                outputType: DockerService::OUTPUT_TYPE_RETURN
            );
            if (is_string($version)) {
                $data[1] = trim(preg_replace('/^' . preg_quote($driver) . '\s*/', '', $version));
            }
        }
        return $data;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['details']);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
    }
}

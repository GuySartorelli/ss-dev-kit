<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Environment;

trait UsesDocker
{
    private DockerService $docker;

    protected function getDockerService()
    {
        if (!isset($this->docker)) {
            $this->docker = new DockerService($this->env, $this->output);
        }
        return $this->docker;
    }
}

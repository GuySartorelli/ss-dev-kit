<?php declare(strict_types=1);

namespace Silverstripe\DevStarterKit\Command\Env;

use Composer\Semver\Semver;
use Silverstripe\DevStarterKit\Command\BaseCommand;
use Silverstripe\DevStarterKit\Utility\Environment;
use Silverstripe\DevStarterKit\Utility\PHPService;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Silverstripe\DevStarterKit\Application;
use Silverstripe\DevStarterKit\IO\StepLevel;
use Silverstripe\DevStarterKit\Trait\UsesDocker;
use Silverstripe\DevStarterKit\Utility\DockerService;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Command which creates a dockerised local development environment
 */
class Create extends BaseCommand
{
    use UsesDocker;

    protected static $defaultName = 'env:create';

    protected static $defaultDescription = 'Installs/clones a Silverstripe CMS installation and sets up a new docker environment with a webhost.';

    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'installer' => 'silverstripe/installer',
        'blog' => 'silverstripe/recipe-blog',
        'core' => 'silverstripe/recipe-core',
        'cms' => 'silverstripe/recipe-cms',
    ];

    /**
     * Characters that cannot be used for an environment name
     * @TODO check if this is still relevant
     */
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;\\';

    protected static bool $notifyOnCompletion = true;

    protected Environment $env;

    protected Filesystem $filesystem;

    protected TwigEnvironment $twig;

    private array $composerArgs = [];

    private int $webPort;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        // @TODO move these checks into the new validate method
        // @TODO don't allow creating a new env (unless using attach mode) if there's ANYTHING in the project dir
        $projectPath = $this->input->getArgument('env-path');
        if (is_dir($projectPath) && Environment::dirIsInEnv($projectPath)) {
            throw new RuntimeException('Project path is inside an existing environment. Cannot create nested environments.');
        }
        if (is_file($projectPath)) {
            throw new RuntimeException('Project path must not be a file.');
        }
        // @TODO convert the normalise methods into a generalised validate method
        $this->normaliseRecipe();
        $this->filesystem = new Filesystem();
        // Do this in initialise so that if there are any issues loading the template dir
        // we error out early
        $twigLoader = new FilesystemLoader(Application::getTemplateDir());
        $this->twig = new TwigEnvironment($twigLoader);
    }

    protected function rollback(): void
    {
        $this->output->endStep(StepLevel::Command, 'Error occurred, rolling back...', success: false);
        if ($this->env && file_exists(Path::join($this->env->getDockerDir(), 'docker-compose.yml'))) {
            $this->output->writeln('Tearing down docker');
            $this->getDockerService()->down(removeOrphans: true, images: true, volumes: true);
        }
        $this->output->writeln('Deleting project dir');
        $this->filesystem->remove($this->env->getProjectRoot());
        $this->output->writeln('Rollback successful');
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        // @TODO change this depending on if attach mode is used
        $this->output->startStep(StepLevel::Command, 'Creating new environment');

        $this->env = new Environment($this->input->getArgument('env-path'), isNew: true);
        $this->env->setPort($this->findPort());
        // @TODO validate project path is empty first! If not empty, recommend using the attach command instead.

        // Raw environment dir setup
        $success = $this->prepareProjectRoot();
        if (!$success) {
            $this->rollback();
            return Command::FAILURE;
        }

        // Docker stuff
        $success = $this->spinUpDocker();
        // @TODO better failure handling - probably just throw some exception
        if (!$success) {
            $this->rollback();
            return Command::FAILURE;
        }

        // @TODO Need a step here to wait to make sure the docker container is actually ready to be used!!
        // Otherwise the first docker command tends to fail!

        // @TODO do this as part of the docker image instead
        $this->setPHPVersion();

        // Composer
        $success = $this->runComposerCommands();
        if (!$success) {
            $this->rollback();
            return Command::FAILURE;
        }

        // app/_config, etc
        $success = $this->copyWebrootFiles();
        if (!$success) {
            $this->rollback();
            return Command::FAILURE;
        }

        // Run dev/build
        $this->buildDatabase();

        // @TODO explicit composer audit

        $url = $this->env->getBaseURL();
        // @TODO consider other output that might be useful here... maybe a full `ss-dev-starter-kit info`?
        $this->output->endStep(StepLevel::Command, 'Completed successfully.');
        $this->output->writeln("Navigate to <href=$url>$url</>");
        return Command::SUCCESS;
    }

    protected function prepareProjectRoot(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Preparing project directory');
        $projectRoot = $this->env->getProjectRoot();
        $mkDirs = [];
        if (!is_dir($projectRoot)) {
            $mkDirs[] = $projectRoot;
        }
        try {
            $mkDirs[] = $metaDir = $this->env->getmetaDir();
            $this->filesystem->mkdir(array_merge($mkDirs, [
                Path::join($metaDir, 'logs'),
                Path::join($metaDir, 'logs/apache2'),
                Path::join($metaDir, 'artifacts'),
            ]));
            $copyFrom = Path::join(Application::getCopyDir(), 'metadir');
            $this->filesystem->mirror($copyFrom, $metaDir);
        } catch (IOException $e) {
            // @TODO replace this with more standardised error/failure handling.
            $this->output->endStep(StepLevel::Primary, "Couldn't create environment directory: {$e->getMessage()}", false);
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            return false;
        }

        $this->output->endStep(StepLevel::Primary);
        return true;
    }

    protected function spinUpDocker(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Spinning up docker');
        // @TODO have a "it's us silverstripe devs" mode that builds out a local copy of the main dockerfile before all of those?
        // Useful to validate local changes e.g. when we add new PHP versions - but not strictly necessary, as we can just do that manually
        // cd dockerdir && docker build -t silverstripe/dev-starter-kit # or whatever
        try {
            $this->output->writeln('Preparing docker directory');
            // Setup docker files
            $dockerDir = $this->env->getDockerDir();
            $copyFrom = Path::join(Application::getCopyDir(), 'docker');
            // Copy files that don't rely on variables
            $this->filesystem->mirror($copyFrom, $dockerDir);
            // Render twig templates for anything else
            $templateRoot = Path::join(Application::getTemplateDir(), 'docker');
            $this->renderTemplateDir($templateRoot, $dockerDir);
        } catch (IOException $e) {
            $this->output->endStep(StepLevel::Primary, "Couldn't set up docker or webroot files: {$e->getMessage()}", false);
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            return false;
        }

        $this->output->startStep(StepLevel::Secondary, 'Starting docker containers');
        $success = $this->getDockerService()->up(build: true);
        $this->output->endStep(StepLevel::Secondary);
        if (!$success) {
            $this->output->endStep(StepLevel::Primary, 'Couldn\'t start docker containers', false);
            return false;
        }

        $this->output->endStep(StepLevel::Primary);
        return true;
    }

    protected function buildDatabase(): bool
    {
        // @TODO The unable to build the db block should be output with `--no-install` as well.
        $this->output->startStep(StepLevel::Primary, 'Building database');

        if (!in_array('--no-install', $this->input->getOption('composer-option'))) {
            // run vendor/bin/sake dev/build in the docker container
            $success = $this->getDockerService()->exec('vendor/bin/sake dev/build', outputType: DockerService::OUTPUT_TYPE_DEBUG);

            if (!$success) {
                $url = "{$this->env->getBaseURL()}/dev/build";

                // Can't use $this->output->warning() because it escapes the link into plain text
                $this->output->block(
                    [
                        'Unable to build the db.',
                        "Build the db by going to <href=$url>$url</>",
                        'Or run: dev-tools sake dev/build -p ' . $this->env->getProjectRoot(),
                    ],
                    type: 'WARNING',
                    style: 'fg=black;bg=yellow',
                    padding: true,
                    escape: false,
                    verbosity: OutputInterface::VERBOSITY_NORMAL
                );
            }
        }

        $this->output->endStep(StepLevel::Primary, success: $success);
        return $success;
    }

    protected function copyWebrootFiles(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Copying extra files into project root');

        $projectDir = $this->env->getProjectRoot();

        // @TODO Some files may already exist if we're attaching or cloning
        // In those cases, ASK if we should replace them. Default to not replacing.

        try {
            // Setup environment-specific web files
            $this->output->writeln('Preparing extra webroot files');
            // Copy files that don't rely on variables
            $this->filesystem->mirror(Path::join(Application::getCopyDir(), 'webroot'), $projectDir, options: ['override' => true]);
            // Render twig templates for anything else
            $templateRoot = Path::join(Application::getTemplateDir(), 'webroot');
            $this->renderTemplateDir($templateRoot, $projectDir);
        } catch (IOException $e) {
            $this->output->endStep(StepLevel::Primary, "Couldn't set up webroot files: {$e->getMessage()}", false);
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            return false;
        }

        // @TODO add to .gitignore if not already present:
        // - /.ss-dev-starter-kit/
        // - /silverstripe-cache/

        $this->output->endStep(StepLevel::Primary);
        return true;
    }

    protected function setPHPVersion()
    {
        $this->output->startStep(StepLevel::Primary, 'Setting appropriate PHP version');
        if ($phpVersion = $this->input->getOption('php-version')) {
            if (PHPService::versionIsAvailable($phpVersion)) {
                $this->usePHPVersion($phpVersion);
                $this->output->endStep(StepLevel::Primary);
                return;
            }

            $this->output->warning("PHP $phpVersion is not available. Falling back to auto-detection");
        }

        // Get the php version for the selected recipe and version
        $recipe = $this->input->getOption('recipe');
        $command = "composer show -a -f json {$recipe} {$this->input->getOption('constraint')}";
        $dockerReturn = $this->getDockerService()->exec($command, outputType: DockerService::OUTPUT_TYPE_RETURN);
        if (!$dockerReturn) {
            $this->output->warning('Could not fetch PHP version from composer. Using default');
            $this->output->endStep(StepLevel::Primary, success: false);
            return;
        }
        // Rip out any composer nonsense before the JSON actually starts, then parse
        $composerJson = json_decode(preg_replace('/^[^{]*/', '', $dockerReturn), true);
        if (!isset($composerJson['requires']['php'])) {
            $this->output->warning("$recipe doesn't have an explicit PHP dependency to check against. Using default");
            $this->output->endStep(StepLevel::Primary, success: false);
            return;
        }
        $constraint = $composerJson['requires']['php'];
        $this->output->writeln("Composer constraint for PHP is <info>$constraint</info>.", OutputInterface::VERBOSITY_DEBUG);

        // Try each installed PHP version against the allowed versions
        foreach (PHPService::getAvailableVersions() as $phpVersion) {
            if (!Semver::satisfies($phpVersion, $constraint)) {
                $this->output->writeln("PHP <info>$phpVersion</info> doesn't satisfy the constraint. Skipping.", OutputInterface::VERBOSITY_DEBUG);
                continue;
            }
            $this->usePHPVersion($phpVersion);
            $this->output->endStep(StepLevel::Primary);
            return;
        }

        $this->output->warning('Could not set PHP version. Using default');
        $this->output->endStep(StepLevel::Primary, success: false);
    }

    /**
     * Swap to a specific PHP version.
     * Note that because this restarts apache it sometimes results in the docker container exiting with non-0
     */
    private function usePHPVersion(string $phpVersion): bool
    {
        $phpService = new PHPService($this->env, $this->output);
        return $phpService->swapToVersion($phpVersion);
    }

    protected function runComposerCommands(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Building composer project');

        $this->output->writeln('Making temporary directory');
        $tmpDir = '/tmp/composer-create-project-' . time();
        $this->getDockerService()->exec("mkdir $tmpDir", outputType: DockerService::OUTPUT_TYPE_DEBUG);

        if ($githubToken = getenv('DT_GITHUB_TOKEN')) {
            $this->output->writeln('Adding github token to composer');
            // @TODO is there any chance of this resulting in the token leaking? How to avoid that if so?
            // @TODO OMIT IT FROM "running command X in docker container" OUTPUT!!!
            $success = $this->getDockerService()->exec("composer config -g github-oauth.github.com $githubToken", outputType: DockerService::OUTPUT_TYPE_DEBUG);
            if (!$success) {
                return false;
            }
        }

        // @TODO explicitly use `--no-install` - do a separate explicit `composer install` at the end of the composer stuff.
        // Alternatively, only use `--no-install` in create-project if there are more composer stuff to do (which is already how it works, except it doesn't respect `--module`)
        // and explicitly have `--no-install` in the require commands, and only add the explicit `composer install` if it's needed.
        $composerCommand = $this->prepareComposerCommand('create-project');

        // Run composer command
        $success = $this->getDockerService()->exec(implode(' ', $composerCommand), workingDir: $tmpDir, outputType: DockerService::OUTPUT_TYPE_DEBUG);
        if (!$success) {
            $this->output->endStep(StepLevel::Primary, 'Couldn\'t create composer project.', false);
            return false;
        }

        $this->output->writeln('Copying composer project from temporary directory');
        $this->getDockerService()->exec("cp -r $tmpDir/* /var/www/", outputType: DockerService::OUTPUT_TYPE_DEBUG);

        $this->output->writeln('Removing temporary directory');
        $this->getDockerService()->exec("rm -rf $tmpDir", outputType: DockerService::OUTPUT_TYPE_DEBUG);

        // Install optional modules if appropriate
        foreach ($this->input->getOption('extra-module') as $module) {
            $success = $success && $this->includeOptionalModule($module);
        }

        // Only returns $result if it represents a failure
        $this->output->endStep(StepLevel::Primary, success: $success);
        return $success;
    }

    private function includeOptionalModule(string $moduleName): bool
    {
        $this->output->startStep(StepLevel::Secondary, "Adding optional module </info>$moduleName</info>");
        $composerCommand = [
            'composer',
            'require',
            $moduleName,
            ...$this->prepareComposerArgs('require'),
        ];

        // Run composer command
        $success = $this->getDockerService()->exec(implode(' ', $composerCommand), outputType: DockerService::OUTPUT_TYPE_DEBUG);
        if (!$success) {
            $this->output->endStep(StepLevel::Secondary, "Couldn't require '$moduleName'.", false);
            return false;
        }
        $this->output->endStep(StepLevel::Secondary);
        return true;
    }

    /**
     * Prepares arguments for a composer command that will result in installing dependencies
     */
    private function prepareComposerArgs(string $commandType): array
    {
        if (!$this->composerArgs) {
            // Prepare composer command
            $this->composerArgs = [
                '--no-interaction',
                '--no-progress',
                ...$this->input->getOption('composer-option')
            ];
        }
        $args = $this->composerArgs;

        // composer install can't take --no-audit, but we don't want to include audits in other commands.
        if ($commandType !== 'install') {
            $args[] = '--no-audit';
        }

        // Make sure --no-install isn't in there twice.
        return array_unique($args);
    }

    /**
     * Prepares a composer install or create-project command
     *
     * @param string $commandType - should be install or create-project
     */
    private function prepareComposerCommand(string $commandType)
    {
        $composerArgs = $this->prepareComposerArgs($commandType);
        $command = [
            'composer',
            $commandType,
            ...$composerArgs
        ];
        if ($commandType === 'create-project') {
            $command[] = $this->input->getOption('recipe') . ':' . $this->input->getOption('constraint');
            $command[] = './';
        }
        return $command;
    }

    /**
     * Render all templates in some directory into some other directory.
     */
    protected function renderTemplateDir(string $templateRoot, string $renderTo): void
    {
        $renderDirName = basename($renderTo);
        $this->output->startStep(StepLevel::Secondary, "Rendering templates into <info>$renderDirName</info>");
        $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot));
        foreach ($dirs as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }
            $template = Path::makeRelative($file->getPathname(), Application::getTemplateDir());
            $templateRelative = preg_replace('/(.*)\.twig$/', '$1', Path::makeRelative($file->getPathname(), $templateRoot));
            $outputPath = Path::makeAbsolute($templateRelative, $renderTo);
            $this->filesystem->dumpFile($outputPath, $this->renderTemplate($template));
        }
        $this->output->endStep(StepLevel::Secondary, 'Finished rendering templates');
    }

    /**
     * Render a template
     */
    protected function renderTemplate(string $template): string
    {
        $templateName = basename($template);
        $this->output->writeln("Rendering <info>$templateName</info>");
        // Prepare template variables
        $hostname = $this->env->getHostName();
        $hostParts = explode('.', $hostname);

        $variables = [
            'projectName' => $this->env->getName(),
            'hostName' => $hostname,
            'hostSuffix' => array_pop($hostParts),
            'database' => $this->input->getOption('db'),
            'dbVersion' => $this->input->getOption('db-version'),
            'attached' => false,
            'webPort' => $this->env->getPort(),
        ];

        return $this->twig->render($template, $variables);
    }

    private function findPort(): ?int
    {
        // Don't bind to any ports if the user doesn't want to
        if ($this->input->getOption('no-port')) {
            return null;
        }

        $port = $this->input->getOption('port');
        if ($port !== null) {
            // Use the port the user declared
            return $port;
        } else {
            $this->output->writeln('Finding port');
            // Let PHP find an available port to bind to
            $socket = socket_create(AF_INET, SOCK_STREAM, 0);
            if (!$socket) {
                throw new LogicException('Could not find open port: ' . socket_strerror(socket_last_error()));
            }
            $bound = socket_bind($socket, 'localhost');
            if (!$bound) {
                throw new LogicException('Could not find open port: ' . socket_strerror(socket_last_error($socket)));
            }
            $gotPort = socket_getsockname($socket, $ip, $port);
            if (!$gotPort) {
                throw new LogicException('Could not find open port: ' . socket_strerror(socket_last_error($socket)));
            }
            socket_close($socket);
            $this->output->writeln("Using port <info>$port</info>");
            return $port;
        }
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    protected function normaliseRecipe(): void
    {
        $recipe = $this->input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $this->input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
        // see https://getcomposer.org/doc/04-schema.md#name for regex
        if (!preg_match('%^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$%', $this->input->getOption('recipe'))) {
            throw new LogicException('recipe must be a valid composer package name.');
        }
    }

    protected function getDefaultEnvName(): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        // Normalise recipe by replacing 'invalid' chars with hyphen
        $recipeParts = explode('-', preg_replace($invalidCharsRegex, '-', $this->input->getOption('recipe')));
        $recipe = end($recipeParts);
        // Normalise constraints to remove stability flags
        $constraint = preg_replace('/^(dev-|v(?=\d))|-dev|(#|@).*?$/', '', $this->input->getOption('constraint'));
        $constraint = preg_replace($invalidCharsRegex, '-', trim($constraint, '~^'));
        $name = $recipe . '_' . $constraint;

        return $name;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['create']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Creates a new environment in the project path using the env name and a unique integer value.
        The environment directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::REQUIRED,
            'The path where the project environment will be created or attached to.'
        );
        // @TODO add attach and clone functionality
        // $this->addOption(
        //     'attach',
        //     'a',
        //     InputOption::VALUE_NEGATABLE,
        //     'Attach a docker environment to an existing silverstripe project directory. Cannot use clone and attach together.',
        //     false,
        // );
        // $this->addOption(
        //     'clone',
        //     'g',
        //     InputArgument::REQUIRED,
        //     'Use "git clone" to clone an existing silverstripe project from a remote git repository into env-path. Cannot use attach and clone together.',
        //     false,
        // );
        $recipeDescription = '';
        foreach (static::$recipeShortcuts as $shortcut => $recipe) {
            $recipeDescription .= "\"$shortcut\" ($recipe), ";
        }
        $this->addOption(
            'recipe',
            'r',
            InputOption::VALUE_REQUIRED,
            'The recipe to install. Options: ' . $recipeDescription . 'any recipe composer name (e.g. "silverstripe/recipe-kitchen-sink")',
            'installer'
        );
        $this->addOption(
            'constraint',
            'c',
            InputOption::VALUE_REQUIRED,
            'The version constraint to use for the installed recipe.',
            getenv('DT_DEFAULT_INSTALL_VERSION')
        );
        // @TODO add include-recipe-testing back in?
        $this->addOption(
            'extra-module',
            'm',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional modules to be required before dev/build.',
            []
        );
        // TODO make this singular, and let it be an array
        $this->addOption(
            'composer-option',
            'o',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional arguments to be passed to the composer create-project command.'
        );
        $this->addOption(
            'php-version',
            'P',
            InputOption::VALUE_REQUIRED,
            'The PHP version to use for this environment. Uses the lowest allowed version by default.'
        );
        $this->addOption(
            'db',
            null,
            InputOption::VALUE_REQUIRED,
            // @TODO we sure we don't want to let postgres and sqlite3 be used?
            'The database type to be used. Must be one of "mariadb", "mysql".',
            'mysql'
        );
        $this->addOption(
            'db-version',
            null,
            InputOption::VALUE_REQUIRED,
            'The version of the database docker image to be used.',
            'latest'
        );
        // @TODO decide how to add host name
        // @TODO consider doing something more like Symfony's CLI which just finds an arbitrary available port
        // see https://github.com/search?q=repo%3Asymfony-cli%2Fsymfony-cli%20FindAvailablePort&type=code
        $this->addOption(
            'port',
            'p',
            InputOption::VALUE_REQUIRED,
            'The port to bind the webserver to. If not defined, a random available port will be used. Use --no-port to explicitly not bind any ports.'
        );
        $this->addOption(
            'no-port',
            null,
            InputOption::VALUE_NONE,
            'Do not bind to any ports on the host machine.'
        );
    }
}

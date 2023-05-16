<?php declare(strict_types=1);

namespace Silverstripe\DevKit\Command\Env;

use Composer\Semver\Semver;
use Silverstripe\DevKit\Command\BaseCommand;
use Silverstripe\DevKit\Environment\Environment;
use Silverstripe\DevKit\Environment\PHPService;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Silverstripe\DevKit\Application;
use Silverstripe\DevKit\Compat\Filesystem;
use Silverstripe\DevKit\Compat\OS;
use Silverstripe\DevKit\IO\StepLevel;
use Silverstripe\DevKit\Environment\UsesDocker;
use Silverstripe\DevKit\Environment\DockerService;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
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

    protected static $defaultDescription = 'Installs a Silverstripe CMS installation and sets up a new docker environment with a webhost.';

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
        $this->filesystem = new Filesystem();
        $this->normaliseRecipe();
        $this->validateOptions();
        // Do this in initialise so that if there are any issues loading the template dir
        // we error out early
        $twigLoader = new FilesystemLoader(Application::getTemplateDir());
        $this->twig = new TwigEnvironment($twigLoader);
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    private function normaliseRecipe(): void
    {
        $recipe = $this->input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $this->input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
    }

    private function validateOptions()
    {
        $this->input->validate();

        // @TODO don't allow creating a new env (unless using attach mode) if there's ANYTHING in the project dir
        $projectPath = $this->input->getArgument('env-path');
        if (Path::isAbsolute($projectPath)) {
            $projectPath = Path::canonicalize($projectPath);
        } else {
           $projectPath = Path::makeAbsolute($projectPath, getcwd());
        }
        if ($this->filesystem->isDir($projectPath) && Environment::dirIsInEnv($projectPath)) {
            throw new RuntimeException('Project path is inside an existing environment. Cannot create nested environments.');
        }
        if ($this->filesystem->isFile($projectPath)) {
            throw new RuntimeException('Project path must not be a file.');
        }

        // @TODO also check for a composer.json file?
        if ($this->input->getOption('attach') && !$this->filesystem->isDir($projectPath)) {
            throw new RuntimeException('Project path must exist when --attach is used');
        }

        if ($this->input->getOption('port') && $this->input->getOption('no-port')) {
            throw new RuntimeException('Cannot use --port and --no-port together');
        }

        // @TODO find a clean way to validate db version? Or just let that error out at a docker level?
        // @TODO make this configurable when we have plugins
        $validDbDrivers = [
            'mysql',
            'mariadb',
        ];
        if (!in_array($this->input->getOption('db'), $validDbDrivers)) {
            throw new RuntimeException('--db must be one of ' . implode(', ', $validDbDrivers));
        }

        $phpVersion = $this->input->getOption('php-version');
        if ($phpVersion !== null && !PHPService::versionIsAvailable($phpVersion)) {
            throw new RuntimeException("PHP version $phpVersion is not available. Use one of " . implode(', ', PHPService::getAvailableVersions()));
        }

        // see https://getcomposer.org/doc/04-schema.md#name for regex
        if (!preg_match('%^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$%', $this->input->getOption('recipe'))) {
            throw new LogicException('recipe must be a valid composer package name.');
        }

        // @TODO validate constraint using composer/semver
    }

    protected function rollback(): void
    {
        $this->output->endStep(StepLevel::Command, 'Error occurred, rolling back...', success: false);
        if ($this->env && $this->filesystem->exists(Path::join($this->env->getDockerDir(), 'docker-compose.yml'))) {
            $this->output->writeln('Tearing down docker');
            $this->getDockerService()->down(removeOrphans: true, images: true, volumes: true);
        }
        if ($this->input->getOption('attach')) {
            // @TODO clean up things like .env, .vscode/ and other webroot files/dirs we've messed with
            $this->output->writeln('Deleting devkit-specific directories');
            $this->filesystem->remove($this->env->getDockerDir());
            $this->filesystem->remove($this->env->getMetaDir());
        } else {
            $this->output->writeln('Deleting project dir');
            $this->filesystem->remove($this->env->getProjectRoot());
        }
        $this->output->writeln('Rollback successful');
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $msg = 'Creating new project and attaching environment';
        if ($this->input->getOption('attach')) {
            $msg = 'Attaching environment to existing project';
        }
        $this->output->startStep(StepLevel::Command, $msg);

        $this->env = new Environment($this->input->getArgument('env-path'), isNew: true);
        $this->env->setPort($this->findPort());

        // Raw environment dir setup
        $success = $this->prepareProjectRoot();
        if (!$success) {
            $this->rollback();
            return self::FAILURE;
        }

        // Docker stuff
        $success = $this->spinUpDocker();
        // @TODO better failure handling - probably just throw some exception
        if (!$success) {
            $this->rollback();
            return self::FAILURE;
        }

        // @TODO set default PHP version as part of the docker image instead
        $this->setPHPVersion();

        // Share composer token
        if ($githubToken = getenv('SS_DK_GITHUB_TOKEN')) {
            $this->output->writeln('Adding github token to composer');
            // @TODO is there any chance of this resulting in the token leaking? How to avoid that if so?
            // @TODO OMIT IT FROM "running command X in docker container" OUTPUT!!!
            $this->getDockerService()->exec("composer config -g github-oauth.github.com $githubToken", outputType: DockerService::OUTPUT_TYPE_DEBUG);
        }

        // Install composer stuff
        if ($this->input->getOption('attach')) {
            $this->composerInstallIfNecessary();
        } else {
            $success = $this->buildComposerProject();
            if (!$success) {
                $this->rollback();
                return self::FAILURE;
            }
        }
        $this->addExtraModules();


        // metadir/_config, etc
        $success = $this->copyWebrootFiles();
        if (!$success) {
            $this->rollback();
            return self::FAILURE;
        }

        // Run dev/build
        $this->buildDatabase();

        // @TODO explicit composer audit

        $url = $this->env->getBaseURL();
        // @TODO consider other output that might be useful here... maybe a full `ss-dev-kit info`?
        $this->output->endStep(StepLevel::Command, 'Completed successfully.');
        $this->output->writeln("Navigate to <href=$url>$url</>");
        return self::SUCCESS;
    }

    protected function prepareProjectRoot(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Preparing project directory');
        $isAttachMode = $this->input->getOption('attach');
        $projectRoot = $this->env->getProjectRoot();
        $mkDirs = [];
        if (!$this->filesystem->isDir($projectRoot)) {
            if ($isAttachMode) {
                throw new RuntimeException('Project root must exist with --attach is used');
            }
            $mkDirs[] = $projectRoot;
        }
        try {
            $mkDirs[] = $metaDir = $this->env->getMetaDir();
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
        // cd dockerdir && docker build -t guysartorelli/ss-dev-kit # or whatever
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
            $this->output->endStep(StepLevel::Primary, "Couldn't set up docker files: {$e->getMessage()}", false);
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

    protected function buildDatabase()
    {
        // @TODO The unable to build the db block should be output with `--no-install` as well.
        $this->output->startStep(StepLevel::Primary, 'Building database');

        if (!in_array('--no-install', $this->input->getOption('composer-option'))) {
            // run vendor/bin/sake dev/build in the docker container
            $success = $this->getDockerService()->exec('vendor/bin/sake dev/build', outputType: DockerService::OUTPUT_TYPE_DEBUG);

            if (!$success) {
                $url = "{$this->env->getBaseURL()}/dev/build";

                // Can't use $this->output->warning() because it escapes the link into plain text
                $this->output->warningBlock(
                    [
                        'Unable to build the db.',
                        "Build the db by going to <href=$url>$url</>",
                        'Or run: ' . Application::getScriptName() . ' exec vendor/bin/sake dev/build -p ' . $this->env->getProjectRoot(),
                    ],
                    escape: false
                );
            }
        }

        $this->output->endStep(StepLevel::Primary, success: $success);
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
        // - /.ss-dev-kit/
        // - /silverstripe-cache/

        $this->output->endStep(StepLevel::Primary);
        return true;
    }

    protected function setPHPVersion()
    {
        $this->output->startStep(StepLevel::Primary, 'Setting appropriate PHP version');
        $phpService = new PHPService($this->env, $this->output);

        if ($phpVersion = $this->input->getOption('php-version')) {
            if (PHPService::versionIsAvailable($phpVersion)) {
                $phpService->swapToVersion($phpVersion);
                $this->output->endStep(StepLevel::Primary, "Using PHP version $phpVersion");
                return;
            }

            $this->output->warning("PHP $phpVersion is not available. Falling back to auto-detection");
        }

        if ($this->input->getOption('attach')) {
            $composerJsonPath = Path::join($this->env->getProjectRoot(), 'composer.json');
            if (!$this->filesystem->exists($composerJsonPath)) {
                $this->output->warning('No composer.json file to determine PHP version. Using default');
                $this->output->endStep(StepLevel::Primary, success: false);
                return;
            }
            $composerRaw = $this->filesystem->getFileContents($composerJsonPath);
            $recipeOrProject = 'Project';
            $require = 'require';
        } else {
            // Get the php version for the selected recipe and version
            $recipe = $this->input->getOption('recipe');
            $command = "composer show -a -f json {$recipe} {$this->input->getOption('constraint')}";
            $dockerReturn = $this->getDockerService()->exec($command, outputType: DockerService::OUTPUT_TYPE_RETURN);
            if (!$dockerReturn) {
                $this->output->warning('Could not fetch PHP version from composer. Using default');
                $this->output->endStep(StepLevel::Primary, success: false);
                return;
            }
            // Rip out any composer nonsense before the JSON actually starts
            $composerRaw = preg_replace('/^[^{]*/', '', $dockerReturn);
            $recipeOrProject = $recipe;
            $require = 'requires';
        }

        // Check for php version constraint in composer json
        $composerJson = json_decode($composerRaw, true);
        if (!isset($composerJson[$require]['php'])) {
            $this->output->warning("$recipeOrProject doesn't have an explicit PHP dependency to check against. Using default");
            $this->output->endStep(StepLevel::Primary, success: false);
            return;
        }
        $constraint = $composerJson[$require]['php'];
        $this->output->writeln("Composer constraint for PHP is <info>$constraint</info>.", OutputInterface::VERBOSITY_DEBUG);

        // Try each installed PHP version against the allowed versions
        foreach (PHPService::getAvailableVersions() as $phpVersion) {
            if (!Semver::satisfies($phpVersion, $constraint)) {
                $this->output->writeln("PHP <info>$phpVersion</info> doesn't satisfy the constraint. Skipping.", OutputInterface::VERBOSITY_DEBUG);
                continue;
            }
            $phpService->swapToVersion($phpVersion);
            $this->output->endStep(StepLevel::Primary, "Using PHP version $phpVersion");
            return;
        }

        $this->output->warning('Could not set PHP version. Using default');
        $this->output->endStep(StepLevel::Primary, success: false);
    }

    private function composerInstallIfNecessary()
    {
        if ($this->filesystem->isDir(Path::join($this->env->getProjectRoot(), 'vendor')) || !$this->filesystem->exists(Path::join($this->env->getProjectRoot(), 'composer.json'))) {
            $this->output->writeln('Composer dependencies already installed, or no composer.json file found.');
            return true;
        }
        $this->output->startStep(StepLevel::Primary, 'Installing composer dependencies');

        $composerCommand = $this->prepareComposerCommand('install');
        $success = $this->getDockerService()->exec(implode(' ', $composerCommand), outputType: DockerService::OUTPUT_TYPE_DEBUG);
        if (!$success) {
            $this->output->endStep(StepLevel::Primary, 'Couldn\'t install dependencies. Run composer install manually.', false);
            return false;
        }

        $this->output->endStep(StepLevel::Primary);
    }

    protected function buildComposerProject(): bool
    {
        $this->output->startStep(StepLevel::Primary, 'Building composer project');

        $this->output->writeln('Making temporary directory');
        $tmpDir = '/tmp/composer-create-project-' . time();
        $this->getDockerService()->exec("mkdir $tmpDir", outputType: DockerService::OUTPUT_TYPE_DEBUG);

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
        $this->getDockerService()->exec("cp -rT $tmpDir /var/www", outputType: DockerService::OUTPUT_TYPE_DEBUG);

        $this->output->writeln('Removing temporary directory');
        $this->getDockerService()->exec("rm -rf $tmpDir", outputType: DockerService::OUTPUT_TYPE_DEBUG);

        $this->output->endStep(StepLevel::Primary, success: $success);
        return $success;
    }

    protected function addExtraModules()
    {
        $extraModules = $this->input->getOption('extra-module');

        if (empty($extraModules)) {
            return;
        }

        $this->output->startStep(StepLevel::Primary, 'Installing additional modules');

        $success = true;
        foreach ($extraModules as $module) {
            $success = $success && $this->includeOptionalModule($module);
        }

        if (!$success) {
            $this->output->warning('Failed to install at least one optional module. Please check your dependency constraints and install the module manually.');
        }

        $this->output->endStep(StepLevel::Primary, success: $success);
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
        $variables = [
            'projectName' => $this->env->getName(),
            'database' => $this->input->getOption('db'),
            'dbVersion' => $this->input->getOption('db-version'),
            'attached' => false,
            'webPort' => $this->env->getPort(),
            'metaDirName' => Environment::ENV_META_DIR,
            'UID' => OS::getUserID(),
            'GID' => OS::getGroupID(),
            'composerCacheDir' => OS::getComposerCacheDir(),
            'OS' => OS::getOS(),
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
        $this->addOption(
            'attach',
            'a',
            InputOption::VALUE_NEGATABLE,
            'Attach a docker environment to an existing silverstripe project directory. --constraint and --recipe do nothing when this option is used.',
            false,
        );
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
            '^5.0'
        );
        $this->addOption(
            'extra-module',
            'm',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional modules to be required before dev/build.',
            []
        );
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

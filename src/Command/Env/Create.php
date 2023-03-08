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
use Silverstripe\DevStarterKit\Trait\UsesDocker;
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
        $this->io->error('Error occurred, rolling back...');
        if ($this->env && file_exists(Path::join($this->env->getDockerDir(), 'docker-compose.yml'))) {
            $this->io->writeln(self::STYLE_STEP . 'Tearing down docker' . self::STYLE_CLOSE);
            $this->getDockerService()->down(true, true);
        }
        $this->io->writeln(self::STYLE_STEP . 'Deleting project dir' . self::STYLE_CLOSE);
        $this->filesystem->remove($this->env->getProjectRoot());
        $this->io->writeln(self::STYLE_STEP . 'Rollback successful' . self::STYLE_CLOSE);
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(): int
    {
        $this->env = new Environment($this->input->getArgument('env-path'), isNew: true);
        $this->env->setPort($this->findPort());
        // @TODO validate project path is empty first! If not empty, recommend using the attach command instead.

        // Raw environment dir setup
        $failureCode = $this->prepareProjectRoot();
        if ($failureCode) {
            $this->rollback();
            return $failureCode;
        }

        // Docker stuff
        $failureCode = $this->spinUpDocker();
        // @TODO better failure handling that doesn't rely on these esoteric return values
        if ($failureCode) {
            $this->rollback();
            return $failureCode;
        }

        // @TODO Need a step here to wait to make sure the docker container is actually ready to be used!!
        // Otherwise the first docker command tends to fail!

        // @TODO do this as part of the docker image instead
        $this->setPHPVersion();

        // Composer
        $failureCode = $this->runComposerCommands();
        if ($failureCode) {
            $this->rollback();
            return $failureCode;
        }

        // app/_config, etc
        $failureCode = $this->copyWebrootFiles();
        if ($failureCode) {
            $this->rollback();
            return $failureCode;
        }

        // Run dev/build
        $this->buildDatabase();

        // @TODO explicit composer audit

        $this->io->success('Completed successfully.');
        $url = $this->env->getBaseURL();
        $this->io->writeln(self::STYLE_STEP . "Navigate to <href=$url>$url</>" . self::STYLE_CLOSE);
        // @TODO consider other output that might be useful here (port used, etc)... maybe a full `ss-dev-starter-kit info`?
        return Command::SUCCESS;
    }

    protected function prepareProjectRoot(): bool
    {
        $this->io->writeln(self::STYLE_STEP . 'Preparing project directory' . self::STYLE_CLOSE);
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
            $this->io->error("Couldn't create environment directory: {$e->getMessage()}");
            if ($this->io->isDebug()) {
                $this->io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
        return false;
    }

    protected function spinUpDocker(): int|bool
    {
        $this->io->writeln(self::STYLE_STEP . 'Spinning up docker' . self::STYLE_CLOSE);
        // @TODO have a "it's us silverstripe devs" mode that builds out a local copy of the main dockerfile before all of those?
        // Useful to validate local changes e.g. when we add new PHP versions - but not strictly necessary, as we can just do that manually
        // cd dockerdir && docker build -t silverstripe/dev-starter-kit # or whatever
        try {
            $this->io->writeln(self::STYLE_STEP . 'Preparing docker directory' . self::STYLE_CLOSE);
            // Setup docker files
            $dockerDir = $this->env->getDockerDir();
            $copyFrom = Path::join(Application::getCopyDir(), 'docker');
            // Copy files that don't rely on variables
            $this->filesystem->mirror($copyFrom, $dockerDir);
            // Render twig templates for anything else
            $templateRoot = Path::join(Application::getTemplateDir(), 'docker');
            $this->renderTemplateDir($templateRoot, $dockerDir);
        } catch (IOException $e) {
            $this->io->error('Couldn\'t set up docker or webroot files: ' . $e->getMessage());
            if ($this->io->isDebug()) {
                $this->io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        $this->io->writeln(self::STYLE_STEP . 'Starting docker containers' . self::STYLE_CLOSE);
        $success = $this->getDockerService()->up(true);
        if (!$success) {
            $this->io->error('Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function buildDatabase(): bool
    {// @TODO The unable to build the db block should be output with `--no-install` as well.
        if (!str_contains($this->input->getOption('composer-options') ?? '', '--no-install')) {
            // run vendor/bin/sake dev/build in the docker container
            $this->io->writeln(self::STYLE_STEP . 'Building database.' . self::STYLE_CLOSE);
            $sakeReturn = $this->runDockerCommand('vendor/bin/sake dev/build');
        } else {
            $sakeReturn = Command::INVALID;
        }
        if ($sakeReturn !== Command::SUCCESS) {
            $url = "{$this->env->getBaseURL()}/dev/build";

            // Can't use $this->io->warning() because it escapes the link into plain text
            $this->io->block([
                'Unable to build the db.',
                "Build the db by going to <href=$url>$url</>",
                'Or run: dev-tools sake dev/build -p ' . $this->env->getProjectRoot(),
            ], 'WARNING', 'fg=black;bg=yellow', ' ', true, false);
        }
        return $sakeReturn === Command::SUCCESS;
    }

    protected function copyWebrootFiles(): int|bool
    {
        $this->io->writeln(self::STYLE_STEP . '?????? Need good line for "copying files"' . self::STYLE_CLOSE);

        $projectDir = $this->env->getProjectRoot();

        // @TODO Some files may already exist if we're attaching or cloning
        // In those cases, do a best-effort merge
        // The files we care about for those purposes are:
        // behat.yml

        try {
            // Setup environment-specific web files
            $this->io->writeln(self::STYLE_STEP . 'Preparing extra webroot files' . self::STYLE_CLOSE);
            // Copy files that don't rely on variables
            $this->filesystem->mirror(Path::join(Application::getCopyDir(), 'webroot'), $projectDir, options: ['override' => true]);
            // Render twig templates for anything else
            $templateRoot = Path::join(Application::getTemplateDir(), 'webroot');
            $this->renderTemplateDir($templateRoot, $projectDir);
        } catch (IOException $e) {
            $this->io->error('Couldn\'t set up webroot files: ' . $e->getMessage());
            if ($this->io->isVeryVerbose()) {
                $this->io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        // @TODO add to .gitignore if not already present:
        // - /.ss-dev-starter-kit/
        // - /silverstripe-cache/

        return false;
    }

    protected function setPHPVersion()
    {
        $this->io->writeln(self::STYLE_STEP . 'Setting appropriate PHP version.' . self::STYLE_CLOSE);
        if ($phpVersion = $this->input->getOption('php-version')) {
            if (PHPService::versionIsAvailable($phpVersion)) {
                $this->usePHPVersion($phpVersion);
            } else {
                $this->io->warning("PHP $phpVersion is not available. Using default.");
            }
            return;
        }

        // Get the php version for the selected recipe and version
        $recipe = $this->input->getOption('recipe');
        $command = "composer show -a -f json {$recipe} {$this->input->getOption('constraint')}";
        $dockerReturn = $this->runDockerCommand($command, returnOutput: true);
        if ($dockerReturn === Command::FAILURE) {
            $this->io->warning('Could not fetch PHP version from composer. Using default.');
            return;
        }
        // Rip out any composer nonsense before the JSON actually starts, then parse
        $composerJson = json_decode(preg_replace('/^[^{]*/', '', $dockerReturn), true);
        if (!isset($composerJson['requires']['php'])) {
            $this->io->warning("$recipe doesn't have an explicit PHP dependency to check against. Using default.");
            returnhttps://github.com/search?q=repo%3Asymfony-cli%2Fsymfony-cli%20FindAvailablePort&type=code;
        }
        $constraint = $composerJson['requires']['php'];
        if ($this->io->isVerbose()) {
            $this->io->writeln("Constraint for PHP is $constraint.");
        }

        // Try each installed PHP version against the allowed versions
        foreach (PHPService::getAvailableVersions() as $phpVersion) {
            if (!Semver::satisfies($phpVersion, $constraint)) {
                if ($this->io->isVerbose()) {
                    $this->io->writeln("PHP $phpVersion doesn't satisfy the constraint. Skipping.");
                }
                continue;
            }
            $this->usePHPVersion($phpVersion);
            return;
        }

        $this->io->warning('Could not set PHP version. Using default.');
    }

    /**
     * Swap to a specific PHP version.
     * Note that because this restarts apache it sometimes results in the docker container exiting with non-0
     */
    private function usePHPVersion(string $phpVersion): int
    {
        $phpService = new PHPService($this->env, $this->output);
        return $phpService->swapToVersion($phpVersion);
    }

    protected function runComposerCommands(): int|bool
    {
        $this->io->writeln(self::STYLE_STEP . 'Building composer project' . self::STYLE_CLOSE);

        $this->io->writeln(self::STYLE_STEP . 'Making temporary directory' . self::STYLE_CLOSE);
        $tmpDir = '/tmp/composer-create-project-' . time();
        $this->runDockerCommand("mkdir $tmpDir");

        if ($githubToken = getenv('DT_GITHUB_TOKEN')) {
            $this->io->writeln(self::STYLE_STEP . 'Adding github token to composer' . self::STYLE_CLOSE);
            // @TODO is there any chance of this resulting in the token leaking? How to avoid that if so?
            // @TODO OMIT IT FROM "running command X in docker container" OUTPUT!!!
            $failureCode = $this->runDockerCommand("composer config -g github-oauth.github.com $githubToken");
            if ($failureCode !== Command::SUCCESS) {
                return $failureCode;
            }
        }

        // @TODO explicitly use `--no-install` - do a separate explicit `composer install` at the end of the composer stuff.
        // Alternatively, only use `--no-install` in create-project if there are more composer stuff to do (which is already how it works, except it doesn't respect `--module`)
        // and explicitly have `--no-install` in the require commands, and only add the explicit `composer install` if it's needed.
        $composerCommand = $this->prepareComposerCommand('create-project');

        // Run composer command
        $result = $this->runDockerCommand(implode(' ', $composerCommand), workingDir: $tmpDir);
        if ($result !== Command::SUCCESS) {
            $this->io->error('Couldn\'t create composer project.');
            return $result;
        }

        $this->io->writeln(self::STYLE_STEP . 'Copying composer project from temporary directory' . self::STYLE_CLOSE);
        $this->runDockerCommand("cp -r $tmpDir/* /var/www/");

        $this->io->writeln(self::STYLE_STEP . 'Removing temporary directory' . self::STYLE_CLOSE);
        $this->runDockerCommand("rm -rf $tmpDir");

        // Install optional modules if appropriate
        foreach ($this->input->getOption('extra-modules') as $module) {
            $result = $result ?: $this->includeOptionalModule($module);
        }

        // Only returns $result if it represents a failure
        return $result ?: false;
    }

    private function includeOptionalModule(string $moduleName)
    {
        $this->io->writeln(self::STYLE_STEP . "Adding optional module $moduleName" . self::STYLE_CLOSE);
        $composerCommand = [
            'composer',
            'require',
            $moduleName,
            ...$this->prepareComposerArgs('require'),
        ];

        // Run composer command
        $result = $this->runDockerCommand(implode(' ', $composerCommand));
        if ($result !== Command::SUCCESS) {
            $this->io->error("Couldn't require '$moduleName'.");
            return $result;
        }
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
                ...explode(' ', $this->input->getOption('composer-options') ?? '')
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
    }

    /**
     * Render a template
     */
    protected function renderTemplate(string $template): string
    {
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
            'extra-modules',
            'm',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional modules to be required before dev/build.',
            []
        );
        // TODO make this singular, and let it be an array
        $this->addOption(
            'composer-options',
            'o',
            InputOption::VALUE_REQUIRED,
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

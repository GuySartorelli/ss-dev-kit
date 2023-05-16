# Usage

This is a tool to be run in a terminal. If you're using native windows, and you don't have a terminal of choice, I recommend powershell.

Running `ss-dev-kit` or `ss-dev-kit list` will give you a list of available commands. These are split up into a few different categories.

To get more information about a specific command, either run `ss-dev-kit help <command_name>` or `ss-dev-kit <command_name> -h` - for example `ss-dev-kit create -h`

I will only describe some very common tasks here - but take a look at the available commands and see what you can do with them for yourself. If anything is unclear, or you're unsure how to do something or how something works, or if you run into any problems, or want to do something that it doesn't look like there's a command for - in any of these scenarios, please open a github issue or (if I've specifically asked you to test this thing) contact me on slack.

Also note that for clarity I'm using the full option names in these examples (e.g. `--php-version="8.2"`) but the `help` command will show you shortcuts for these (e.g. for `--php-version="8.2"` you can just use `-p 8.2`) which I fully recommend using.

## Creating a new Silverstripe CMS project from scratch

`ss-dev-kit create -h` will show you what options you have in this regard.

If you just want to spin up a basic install using the latest stable version of [`silverstripe/installer`](https://github.com/silverstripe/silverstripe-installer), and you don't have any specific infrastructure requirements, you can just run the create command and pass in a path where you want this to be installed.

```sh
ss-dev-kit create path/to/project
```

That will spin up a docker environment for you, figure out what version of PHP it should be using (i.e. the lowest version compatible with that version of Silverstripe CMS), find a random port to bind to, install Silverstripe CMS, and ultimately spit out a link for you to view the site in your browser.

If you want to use a specific recipe or composer constraint, you can declare those using the `--recipe` and `--constraint` options.
e.g to install `silverstripe/recipe-blog` with the constraint `^2`:

```sh
ss-dev-kit create --recipe="blog" --constraint="^2" path/to/project
```

There are all sorts of additional options available with this command, including php version, database driver and version, define a specific port to bind to, etc.

### Default configuration

This tool spits out a basic `.env` file for you with the following defaults:

|||
|-------|-------|
|Admin username|`admin`|
|Admin password|`admin`|
|environment type|`dev`|

It also sets up the configuration necessary for emails to be sent to mailhog - but that's a work in progress (no way to access mailhog currently even though it's running).

There's no SSL currently, so it overrides any HTTPS redirects to avoid problems on that front.

There are logs in the `.ss-dev-kit/logs/` directory of the project. It has apache logs and (if there is any output _to_ log), a `silverstripe.log` file for silverstripe logs.

There's also a `.vscode/` directory added to the project root, which adds the config necessary for xdebug in vscode. No other IDEs supported yet, but that's on the TODO list.

### Destroying the environment afterward

To destroy the environment, just run

```sh
ss-dev-kit destroy path/to/project
```

This will tear down the docker environment (including removing volumes, unnecessary images, etc) and delete the entire project directory.

## Attaching a dev environment to an existing Silverstripe CMS project

If you have a project already started (e.g. cloned from some git repostitory), you can attach a development environment to that existing project:

```sh
ss-dev-kit create --attach path/to/project
```

A lot of the same behaviours and options still apply, but the `--constraint` and `--recipe` options are meaningless when attaching to an existing project.

If your project does _not_ declare PHP as one of its dependencies, this tool won't be able to guess what PHP version to use, so you'll need to declare one explicitly in that scenario.

The tool will run `composer install` _only_ if there's no `vendor/` directory in your existing project. It will also run `sake dev/build` for you, so just like the normal command you'll get a URL after running that command which should work in your browser right away.

### Detaching from the environment afterward

If you want to delete the whole project directory, just [run the `destroy` command](#destroying-the-environment-afterward).

If you want to keep the project directory and just detach the dockerised development environment from it, run:

```sh
ss-dev-kit destroy --detach path/to/project
```

This will remove the extra configuration and tear down the docker environment, but it'll leave your project directory there for you to keep working with.

## Getting metadata about the environment

If you need to get the URL for an environment again, or forget what PHP version it's using, or it seems like the environment isn't running and you want to check, you can run the `details` command to get that sort of information:

```sh
ss-dev-kit details path/to/project
```

NOTE: if your current working directory is already inside the project directory (even deep down, e.g. in `path/to/project/app/src/Controller/`), you can omit the `path/to/project` argument - the dev kit will detect the environment related to the project you're already looking at.

## Running composer, sake, etc inside the docker container

You can run any commands you like inside the docker containers by running the `exec` command, e.g. to run `composer install --prefer-source` inside the webserver container:

```sh
ss-dev-kit exec --env-path="path/to/project" -- composer install --prefer-source
```

Note the extra `--` there - that separates options from the `ss-dev-kit` commands from options you're passing through to composer.

You can also run commands in any other containers attached to the project. For example, to open an interactive bash shell in the database container as root:

```sh
ss-dev-kit exec --env-path="path/to/project" --privileged --container="database" bash
```

NOTE: if your current working directory is already inside the project directory (even deep down, e.g. in `path/to/project/app/src/Controller/`), you can omit the `--env-path="path/to/project"` option - the dev kit will detect the environment related to the project you're already looking at.

## Changing PHP versions or running xdebug

You can change what PHP version the webserver container is running or toggle xdebug on/off with the `phpconfig` command.

```sh
# Swap to using PHP 8.2
ss-dev-kit phpconfig --env-path="path/to/project" --php-version="8.2"

# Toggle xdebug on/off
ss-dev-kit phpconfig --env-path="path/to/project" --toggle-debug
```

Don't forget to update your composer dependencies after swapping PHP versions.

NOTE: if your current working directory is already inside the project directory (even deep down, e.g. in `path/to/project/app/src/Controller/`), you can omit the `--env-path="path/to/project"` option - the dev kit will detect the environment related to the project you're already looking at.

Note that xdebug configuration is only included for vscode by default for now. Wider IDE support is on the todo list.

Note also that if you want _information_ about the debug status or php version, you want to [run the `Details` command](#getting-metadata-about-the-environment).

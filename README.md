# Silverstripe Developer Kit

This is a set of tools based around creating and managing dockerised web development environments for Silverstripe CMS projects. Its initial aim is to lower the barrier for entry for local development of Silverstripe CMS projects.

It's very much a work in progress, and is something I am doing in my spare time.

## Requirements

### Operating System

This has so far only been tested in Ubuntu 22.04, but the hope is that it will run on:

- Any modern Linux distrobution
- Any recent MacOS
- Windows 10/11 with WSL2
- Native Windows 10/11 (this one will be the least likely to work right now but please do give it a go and let me know what I need to fix)

### Other software

|Software|Installation instructions|Notes|
|-------|-------|-------|
|PHP 8.1+|[official instructions](https://www.php.net/manual/en/install.php)|I don't _think_ this needs any optional extensions - if it does, please let me know and I'll update this and the composer.json dependencies|
|Docker|[official instructions](https://docs.docker.com/engine/install/)||
|Docker compose 2|[official instructions](https://docs.docker.com/compose/install/)|If you install docker desktop, you do not need to separately install docker compose|
|Composer|[official instructions](https://getcomposer.org/download/)|This won't be necessary eventually. For now, if you don't want to install Composer, you can run Composer from a docker container - instructions for this are below|

## Installation and setup

Note that for now I'm just writing these all as bash commands - if you're using native windows and you don't know how to convert these instructions, feel free to ask for help.

### Grab from git and install dependencies

Eventually this will be packaged as a phar - but while it's still a WIP you'll need to clone this repository and install the dependencies via Composer.

```sh
cd path/to/wherever/you/want/to/put/this
git clone git@github.com:GuySartorelli/ss-dev-starter-kit.git ./
composer install
```

<details>
<summary>If you skipped installing Composer, see these steps</summary>
NOTE: If you opted to not install Composer, you can run Composer from a one-off docker container instead. See https://hub.docker.com/_/composer/ for instructions - but in most cases it probably looks like this:

```sh
docker run --rm --interactive --tty --user $(id -u):$(id -g) -volume $PWD:/app composer install
```
</details>

### Build base docker image

Eventually _this_ step won't be necessary either - I'll host this image on docker hub some day and have it be periodically automatically built to keep it up to date. For now, you'll need to build this image locally. Each environment uses this image as a base.

```sh
docker build -t guysartorelli/ss-dev-kit ./docker
```

### Add the executable to your `PATH`

Again, once we have a phar we'll probably also have an installer step handle this for you. For now, it's a one-off manual step.

You probably don't want to be running `/home/gsartorelli/some/path/ss-devkit/bin/ss-dev-kit create` all the time, so lets add the executable to the `PATH` so you can just run `ss-dev-kit create` instead.

There are two ways to handle this:

#### Actually add it to `PATH` (native Windows)

If you're running native windows (NOT WSL2), this is your only choice (probably).

I'm only going to give native windows instructions for this option. If you're using a unix-based system, you probably want the second option, but if you _really_ want this option then you probably already know how to do it.

The following instructions are derived from https://helpdeskgeek.com/windows-10/add-windows-path-environment-variable/:

First, copy the path to the `bin/` directory in this project. Probably something like `C:\Downloads\ss-dev-starter-kit\bin` (the `\bin` part is important).

1. Open the control panel and go to "system", then click "Advanced system settings"
1. In the dialog window that appears, click the "Advanced" tab and then "Environment Variables" near the bottom.
1. Click on "Path" in the "User variables" section, then click "Edit"
1. Click "New" and paste in the path you copied earlier
1. Click "OK" and close any remaining windows from previous steps.

Now you should be able to simply run `ss-dev-kit` from the command line.

#### Use a symlink (Unix-based systems)

If you're running Linux, Windows with WSL2, or MacOS, you can add a symlink of the executable to a directory that's already in your `PATH`. This is a little tidier, and a little easier.

Make sure your current working directory is the root directory where you've installed this. You'll know you're in the right place if running `ll ./bin/` shows you the `ss-dev-kit` file.

```sh
ln -s $(pwd)/bin/ss-dev-kit /usr/local/bin
```

Now you should be able to simply run `ss-dev-kit` from the command line.

## Usage

This is a tool to be run in a terminal. If you're using native windows, and you don't have a terminal of choice, I recommend powershell.

Running `ss-dev-kit` or `ss-dev-kit list` will give you a list of available commands. These are split up into a few different categories.

To get more information about a specific command, either run `ss-dev-kit help <command_name>` or `ss-dev-kit <command_name> -h` - for example `ss-dev-kit create -h`

I will only describe some very common tasks here - but take a look at the available commands and see what you can do with them for yourself. If anything is unclear, or you're unsure how to do something or how something works, or if you run into any problems, or want to do something that it doesn't look like there's a command for - in any of these scenarios, please open a github issue or (if I've specifically asked you to test this thing) contact me on slack.

Also note that for clarity I'm using the full option names in these examples (e.g. `--php-version="8.2"`) but the `help` command will show you shortcuts for these (e.g. for `--php-version="8.2"` you can just use `-p 8.2`) which I fully recommend using.

### Creating a new Silverstripe CMS project from scratch

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

#### Default configuration

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

#### Destroying the environment afterward

To destroy the environment, just run

```sh
ss-dev-kit destroy path/to/project
```

This will tear down the docker environment (including removing volumes, unnecessary images, etc) and delete the entire project directory.

### Attaching a dev environment to an existing Silverstripe CMS project

If you have a project already started (e.g. cloned from some git repostitory), you can attach a development environment to that existing project:

```sh
ss-dev-kit create --attach path/to/project
```

A lot of the same behaviours and options still apply, but the `--constraint` and `--recipe` options are meaningless when attaching to an existing project.

If your project does _not_ declare PHP as one of its dependencies, this tool won't be able to guess what PHP version to use, so you'll need to declare one explicitly in that scenario.

The tool will run `composer install` _only_ if there's no `vendor/` directory in your existing project. It will also run `sake dev/build` for you, so just like the normal command you'll get a URL after running that command which should work in your browser right away.

#### Detaching from the environment afterward

If you want to delete the whole project directory, just [run the `destroy` command](#destroying-the-environment-afterward).

If you want to keep the project directory and just detach the dockerised development environment from it, run:

```sh
ss-dev-kit destroy --detach path/to/project
```

This will remove the extra configuration and tear down the docker environment, but it'll leave your project directory there for you to keep working with.

### Getting metadata about the environment

If you need to get the URL for an environment again, or forget what PHP version it's using, or it seems like the environment isn't running and you want to check, you can run the `details` command to get that sort of information:

```sh
ss-dev-kit details path/to/project
```

NOTE: if your current working directory is already inside the project directory (even deep down, e.g. in `path/to/project/app/src/Controller/`), you can omit the `path/to/project` argument - the dev kit will detect the environment related to the project you're already looking at.

### Running composer, sake, etc inside the docker container

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

### Changing PHP versions or running xdebug

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

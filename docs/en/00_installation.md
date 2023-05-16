# Installation and setup

These instructions assume you have installed the [requirements mentioned in the readme](../../README.md#requirements).

Note that for now I'm just writing these all as bash commands - if you're using native windows and you don't know how to convert these instructions, feel free to ask for help.

## Grab from git and install dependencies

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

## Build base docker image

Eventually _this_ step won't be necessary either - I'll host this image on docker hub some day and have it be periodically automatically built to keep it up to date. For now, you'll need to build this image locally. Each environment uses this image as a base.

```sh
docker build -t guysartorelli/ss-dev-kit ./docker
```

## Add the executable to your `PATH`

Again, once we have a phar we'll probably also have an installer step handle this for you. For now, it's a one-off manual step.

You probably don't want to be running `/home/gsartorelli/some/path/ss-devkit/bin/ss-dev-kit create` all the time, so lets add the executable to the `PATH` so you can just run `ss-dev-kit create` instead.

There are two ways to handle this:

### Actually add it to `PATH` (native Windows)

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

### Use a symlink (Unix-based systems)

If you're running Linux, Windows with WSL2, or MacOS, you can add a symlink of the executable to a directory that's already in your `PATH`. This is a little tidier, and a little easier.

Make sure your current working directory is the root directory where you've installed this. You'll know you're in the right place if running `ll ./bin/` shows you the `ss-dev-kit` file.

```sh
ln -s $(pwd)/bin/ss-dev-kit /usr/local/bin
```

Now you should be able to simply run `ss-dev-kit` from the command line.

## Environment variables

Until we have a phar release of this, you can choose to either set environment variables in the normal way for your operating system, or in a `.env` file in the directory you've installed this to.

Currently the only environment variable is `SS_DK_GITHUB_TOKEN` which is used to define a github token to pass to composer in case you need composer to be able to access any private repositories. If you're not using private repos, it's probably not worth setting.

## Composer cache

To speed things along, the dev kit tries to share your system's composer cache with the webserver container so it's not downloading everything from scratch all the time. It'll try to automagically find the directory, bt if you notice it's pretty slow at doing composery things you might want to set the `COMPOSER_CACHE_DIR` environment variable (see https://getcomposer.org/doc/03-cli.md#composer-cache-dir)

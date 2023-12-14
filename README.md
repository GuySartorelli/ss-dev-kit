# Silverstripe Developer Kit

This is a set of tools based around creating and managing dockerised web development environments for Silverstripe CMS projects. Its initial aim is to lower the barrier for entry for local development of Silverstripe CMS projects.

It's very much a work in progress, and is something I am doing in my spare time.

Please let me know if you have any recommendations for improving this thing - but check the [to do list](todo.md) first.

## DISCONTINUED

Use [DDEV](https://ddev.readthedocs.io/) instead.

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

## Docs

- [Installation and setup](docs/en/00_installation.md)
- [Usage](docs/en/01_usage.md)

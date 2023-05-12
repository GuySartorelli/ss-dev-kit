# Things to do

## IMMEDIATE NEXT STEPS

- Fix bug where hidden files don't get mirrored across after composer create-project!! AHHHH!
- Make attach mode a little friendlier (e.g. don't overwrite files without permission)
- Make sure all of the defaults are sane
  - After this point, we can get some guinea pigs
- Add some basic validation of prerequisites (docker and docker composer installed)
- Add format option to db dump command
- deal with TODO comments in code
- Cleaner exception handling
- DB commands (dump and restore)
- shortcut commands (composer, sake)
- add phpcs config and fix linting issues
- documentation
- basic unit tests
  - After this point, it could be publishable

## Reverse proxy

### Intention

- Provide hostname support without having to modify hosts file every time a new env is set up
- Automagically handle HTTPS without needing to expose a second port

### Bonus points

- Provide some means to manage or at least see all environments that have been set up with this tool
- Remove need to expose ports at all from individual environments
- Slightly more parity with SS cloud if it uses nginx for the reverse proxy - but frankly that would be a byproduct rather than an explicit goal so probably won't happen

### Possible solutions

- See how symfony CLI does it <https://symfony.com/doc/current/setup/symfony_server.html#setting-up-the-local-proxy>
- Or see how lando does it: https://docs.lando.dev/lando-101/lando-proxy.html
- Or maybe offload this to [traefik](https://doc.traefik.io/traefik/providers/docker/)
- Consider using a [lockable command](https://kurozumi.github.io/symfony-docs/console/lockable_trait.html) for this to avoid multiple proxies being set up accidentally

## Create command

- Move port into the docker .env file. Makes it easier to change and easier to fetch
- If we don't already, validate that we're not trying to create inside an existing environment. Nested environments are not supported.
- Auto-generated SSL cert (and make sure we have an SSL and a non-SSL port? Does symfony do that?) (probably will be part of the proxy, especially if using traefik)
- Set the docker starting php version in the per-project docker image or as part of the startup script. That way `docker compose down && docker compose up -d` will give a container with the correct PHP version.
- Add a healthcheck to the webserver container - check if apache is running.
- Allow using forks of the recipe or of the additional modules. Especially needed for private recipe support.
- Don't do the first composer install if we have forks or additional modules
  - instead, `create-project` must have `--no-install`, as should each `require` statement.
  - Do a final `composer install` after everything is finally settled (unless `--no-install` is in compsoer args option)
- Add a `--no-dev-build` option (useful for restoring an existing db instead or using populate - saves a few seconds and means ss default records don't get added if not wanted)

## Other commands

- Should Details command show the FULL php version (e.g. '8.1.17')?
- Should Details command check if mailhog is running (check the pid in `/home/www-data/mailhog.pid`)?
- Is there a way to get mailhog to run under some path (e.g. `http://localhost:8080/__mailhog`) for when we're using a port?
  - Yes, probably, via apache proxy
- Launch/open command - launches the environment in the default browser
  - Inspired by https://github.com/ddev/ddev
  - Works for any given OS
  - Include this as an option for env:create to auto-launch on successful creation
- Add rollback to the database commands (dump should remove the temp file on failure, restore should have a backup of the orig db to rollback to)

## Output

- Have clean global error/exception handling
  - Probably most places where I'm returning a success boolean should just thrown an exception on failure instead.
  - According to symfony docs "The full exception stacktrace is printed if the VERBOSITY_VERBOSE level or above is used." Find out how to change that so it only happens in debug mode. Verbose is for users of the system, the stack trace is for debugging the system.

## General things

- Autocomplete
  - Official way (requires user to do some stuff)
  - Unofficial way (also requires user to do some setup - so why does this option exist? Is it actually somehow useful?): <https://github.com/stecman/symfony-console-completion>
- Consider using [events](https://kurozumi.github.io/symfony-docs/components/console/events.html) as appropriate - e.g. listening to the terminate event could make sure an environment being created via create command gets rolled back if command terminated early.
- Add a clean command shortcut system
  - e.g. `devkit composer` is a shortcut for `devkit docker:exec composer`
  - Maybe use inline commands via `Command::setCode()` - and give each shortcut the `shortcut:` prefix (e.g. `shortcut:composer` with alias `composer`)
  - has a divider in `devkit list` called "command shortcuts" similar to the "env" or "docker" dividers
  - `devkit help composer` works in an expected way (i.e. shows description for the shortcut with the appropriate args/options)
  - Note that composer specifically might actually end up being its own command instead, and just pass all arguments/etc directly to composer. If so, have to make sure it can do autocomplete neatly too (when composer is installed natively - but also maybe pass through to the docker container and ask IT for autocomplete?).
- Support for more IDEs (i.e. xdebug config)

## Eventually

- Unit tests
  - How do you even test something like this?
    - See how laravel and symfony test theirs
    - See how composer does its testing
- Better documentation

### Plugins

- Have a dir in the home directory (cross-platform - e.g. with `Path::getHomeDirectory()`) e.g. `/home/gsartorelli/.ss-dev-kit/plugins/` with a composer.json
- Plugins must have a special type e.g. "silverstripe-devkit-plugin"
- Have a plugin command to handle plugins
  - Do a `composer require` in that directory to install plugins
    - ~Yes this requires installing composer on the host machine. I think that's fine.~
    - Just use `docker run --rm --interactive --tty --user $(id -u):$(id -g) -volume $PWD:/app composer require` - see https://hub.docker.com/_/composer/
  - Probably use the autoloader from that dir after the main autoloader
    - Might need to do something funky to avoid duplicates? Not sure how that works.
  - If we don't have clean config and extensible APIs by then, just rip them from framework (though could use symfony event handling instead of extensible)
    - Worst case people say "Put that back and write your own thing"
    - More likely people will just accept it and not incorporate into framework and we have duplicate code
    - Best case people go "oh yeah that DOES make sense" and finally accept those RFCs

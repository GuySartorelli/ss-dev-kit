# Things to do

## IMMEDIATE NEXT STEPS

- make sure this thing works on mac and WSL2
  - Bonus points for it working natively on windows
- Make attach mode a little friendlier (e.g. don't overwrite files without permission)
- Make sure all of the defaults are sane
- PHP error handling (just promote to ErrorException) so commands can roll back
- Add some _VERY_ basic documentation
  - After this point, we can get some guinea pigs
- Add format option to db dump command
- deal with TODO comments in code
- error and exception handling?
- DB commands (dump and restore)
- shortcut commands (composer, sake)
- add phpcs config and fix linting issues
- documentation
- basic unit tests
  - After this point, it could be publically available

## Create command

- Move port into the .env file. Makes it easier to change and easier to fetch
- If we don't already, validate that we're not trying to create inside an existing environment. Nested environments are not supported.
- Figure out a better system for dealing with hosts (find out how symfony deals with this - if they have a similar tool)
  - i.e. we don't want to be asking for peoples' passwords.
  - Look at other similar projects
    - There was one showcased at stripecon I think?
      - Turns out that was literally just <https://github.com/silverstripe/silverstripe-serve>
      - [Laravel valet](https://laravel.com/docs/10.x/valet) was also mentioned. Looks like that's nginx so that's a non-starter
      - Laravel also has [sail](https://laravel.com/docs/10.x/sail) which is also nginx but does have a lot of similarities with this so worth checking out [on github](https://github.com/laravel/sail/)
    - Drupal probably has something similar
      - Drupal just uses the PHP built-in server bound to a local port
    - Symfony probably has something similar
      - Symfony cli binds ports by default.
      - Has a proxy for using hostname: <https://symfony.com/doc/current/setup/symfony_server.html#setting-up-the-local-proxy>
        - Use a lockable command for this: <https://kurozumi.github.io/symfony-docs/console/lockable_trait.html>
        - Or maybe offload this to [traefik](https://doc.traefik.io/traefik/providers/docker/)
      - Should be really easy to do something similar.
- Auto-generated SSL cert (and make sure we have an SSL and a non-SSL port? Does symfony do that?) (probably will be part of the proxy, especially if using traefik)
- Set the docker starting php version in the per-project docker image or as part of the startup command. That way `docker compose down && docker compose up -d` will give a container with the correct PHP version.
  - Maybe respect [`.platform.yml`](https://servicedesk.silverstripe.cloud/support/solutions/articles/75000012884-server-configuration-using-platform-yml), falling back to composer platform config when picking php version - alert user about mismatch and confirm version to use if both are present but not the same.
- Maybe add a healthcheck to docker containers - db healthy if mysql is running and ready, webserver healthy if apache is running and ready.
- Consider adding a `--forked-dependency` or similar option (which reuses PR stuff from the old command) and make it add the forks to the composer.json like it would for `--pr` with `--pr-has-deps`
- When there's an error that results in not being able to do the thing, output the error message in a clean error format before rolling back
  - For that matter, put most things (if not everything) in a clean try/finally - on finally, if we weren't successful ask "Something went wrong. Do you want to keep the project folder for debugging?" (or just rollback without asking) (or see note about events below)
- Don't do the first composer install if we have forks or additional modules
  - instead, `create-project` must have `--no-install`, as should each `require` statement.
  - Do a final `composer install` after everything is finally settled (unless `--no-install` is in compsoer args option)
- Add a `--no-dev-build` option (useful for restoring an existing db instead or using populate)

## Other commands

- Should Info command show the FULL php version (e.g. '8.1.17')?
- Should info command check if mailhog is running (check the pid in `/home/www-data/mailhog.pid`)
- Is there a way to get mailhog to run under some path (e.g. `http://localhost:8080/__mailhog`) for when we're using a port?
  - Yes, probably, via apache proxy
- Launch command - launches the environment in the default browser
  - Inspired by https://github.com/ddev/ddev
  - Works for any given OS
  - Include this as an option for env:create to auto-launch on successful creation
- Add rollback to the database commands (dump should remove the temp file on failure, restore should have a backup of the orig db to rollback to)

## Output

- Have clean global error handling
  - Promote errors to exceptions so we can catch them.
  - Probably most places where I'm returning a success boolean should just thrown an exception on failure instead.
  - According to symfony docs "The full exception stacktrace is printed if the VERBOSITY_VERBOSE level or above is used." - find out how to change that so it only happens in debug mode. Verbose is for users of the system, the stack trace is for debugging the system.

## General things

- Autocomplete
  - Official way (requires user to do some stuff)
  - Unofficial way (also requires user to do some stuff - so why does this option exist?): <https://github.com/stecman/symfony-console-completion>
- Use [events](https://kurozumi.github.io/symfony-docs/components/console/events.html) as appropriate - e.g. terminate event could make sure an environment being created via create command gets torn down if command terminated early.
- Add a clean command shortcut system
  - e.g. `devkit composer` is a shortcut for `devkit docker:exec composer`
  - has a divider in `devkit list` called "command shortcuts" similar to the "env" or "docker" dividers
  - `devkit help composer` works in an expected way (i.e. shows description for the shortcut with the appropriate args/options)
  - Note that composer specifically might actually end up being its own command instead, and just pass all arguments/etc directly to composer. If so, have to make sure it can do autocomplete neatly too (when composer is installed natively - but also maybe pass through to the docker container and ask IT for autocomplete?).

## Eventually

- Unit tests
  - How do you even test something like this?
    - See how laravel and symfony test theirs
    - See how composer does its testing
- Documentation

### Plugins

- Have a dir in the home directory (cross-platform somehow) e.g. `/home/gsartorelli/.ss-dev-kit/plugins/` with a composer.json
- Plugins must have a special type e.g. "silverstripe-devkit-plugin"
- Have a plugin command to handle plugins
  - Do a `composer require` in that directory to install plugins
    - Yes this requires installing composer on the host machine. I think that's fine.
  - Probably use the autoloader from that dir after the main autoloader
    - Might need to do something funky to avoid duplicates? Not sure how that works.
  - If we don't have clean config and extensible APIs by then, just rip them from framework
    - Worst case people say "Put that back and write your own thing"
    - More likely people will just accept it and not incorporate into framework and we have duplicate code
    - Best case people go "oh yeah that DOES make sense" and finally accept those RFCs

Change Log
==========

## [PHP Censor v0.9.0](https://github.com/corpsee/php-censor/tree/0.9.0) (2017-02-11)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.8.0...0.9.0)

* **Fixed multiple install command execution (Now admin and project group don't duplicate).**
* Added yaml highlight for build config in project page.
* Improved Gogs support. Thanks to @vinpel. PullRequest #18.
* Improved dashboard UI.

## [PHP Censor v0.8.0](https://github.com/corpsee/php-censor/tree/0.8.0) (2017-02-09)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.7.0...0.8.0)

* **Refactored console/commands. Removed localization from logs.**
* **Removed hacks for Windows (IS_WIN constant). Because it doesn't work on Windows normally anyway.**
* Improved README and Documentation.
* Added param `config-from-file` for installing application with prepared config:

```bash
cd ./php-censor.local

# Non-interactive installation with prepared config.yml file
./bin/console php-censor:install --config-from-file=yes --admin-name=admin --admin-password=admin --admin-email='admin@php-censor.local'
```

* Added params for non-interactive admin creating:

```bash
cd ./php-censor.local

# Non-interactive admin creating
./bin/console php-censor:create-admin --admin-name=admin --admin-password=admin --admin-email='admin@php-censor.local'
```

* Added caching for public build status badge. Issue #15.
* Added build from Gogs (build type and webhook). The feature is based on @denji`s code. Issue #13.
* Improved Codeception plugin. Thanks to @vinpel. PullRequest #16.
* Updated french translation. Thanks to @vinpel. PullRequest #16.
* Fixed init language. Issue #9.

## [PHP Censor v0.7.0](https://github.com/corpsee/php-censor/tree/0.7.0) (2017-01-29)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.6.0...0.7.0)

* Application closed for search robots
* Improved README.md and added CHANGELOG.md file
* **Renamed application configuration (`app/config.yml`) section for work with queue**

The old way to configure queue:

```yml
php-censor:
  worker:
    host:        localhost
    queue:       php-censor-queue
    job_timeout: 600
```

And a new way:

```yml
php-censor:
  queue:
    host:     localhost
    name:     php-censor-queue
    lifetime: 600
```

* **Added PostgreSQL support as application DB. Changed DB configuration**

The old way to configure DB:

```yml
b8:
  database:
    servers:
      read: 'localhost:3306'
      write: 'localhost:3306'
    name:     php-censor-db
    username: php-censor-user
    password: php-censor-password
```

And a new way:

```yml
b8:
  database:
    servers:
      read:
        - host: localhost
          port: 3306
      write:
        - host: localhost
          port: 3306
    type:     mysql
    name:     php-censor-db
    username: php-censor-user
    password: php-censor-password
```

Type of DB (`type`) should be `mysql` or `pgsql`

## [PHP Censor v0.6.0](https://github.com/corpsee/php-censor/tree/0.6.0) (2017-01-22)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.5.0...0.6.0)

* Added pluggable authentication and LDAP authentication provider

```yml
php-censor:
  security:
    auth_providers:
      internal:
        type: internal
      ldap-php-censor:
        type: ldap
        data:
          host:           'ldap.php-censor.local'
          port:           389
          base_dn:        'dc=php-censor,dc=local'
          mail_attribute: mail
```

If you enter by new LDAP-user, the record in the DB will be created automatically. The basement of the feature is @Adirelle and @dzolotov code.

* **Unified application configuration (app/config.yml) authentication options**

The old way to disable authentication:

```yml
php-censor:
  autentication_settings:
    state:   true
    user_id: 1
```

And a new way:

```yml
php-censor:
  security:
    disable_auth:    true
    default_user_id: 1
```

## [PHP Censor v0.5.0](https://github.com/corpsee/php-censor/tree/0.5.0) (2017-01-21)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.4.0...0.5.0)

* Fixed projects archive (Archived projects can not be built and projects moved to the archive section)
* Added option to the application configuration (`app/config.yml`) to allow/deny removing the build directory after build (`php-censor.build.remove_builds`)

```yml
php-censor:
  build:
    remove_builds: true
```

* Added options to the application configuration (`app/config.yml`) to allow/deny sending errors in the commits/pull requests as comments on Github (`php-censor.github.comments.commit` and `php-censor.github.comments.pull_request`)

```yml
php-censor:
  github:
    token: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
    comments:
      commit:       false
      pull_request: false
```

* Improved plugin Codeception
* **Removed agent/worker Daemon mode (You should use Worker mode instead)**
* **Removed pluginconfig configuration file (You should use plugin full name including the namespace)**

```yml
test:
  \PluginNamespace\Plugin:
    allow_failures: true
```

## [PHP Censor v0.4.0](https://github.com/corpsee/php-censor/tree/0.4.0) (2017-01-15)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.3.0...0.4.0)

* Fixed delete confirmation for all items
* Added ajax update for the main page (dashboard)
* Added public status information to the project page
* UI and localization fixes

## [PHP Censor v0.3.0](https://github.com/corpsee/php-censor/tree/0.3.0) (2017-01-11)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.2.0...0.3.0)

* Improved UI
* Updated dependencies
* Updated PHPUnit from 4.8 to 5.7
* Improved build without config

## [PHP Censor v0.2.0](https://github.com/corpsee/php-censor/tree/0.2.0) (2017-01-07)

[Full Changelog](https://github.com/corpsee/php-censor/compare/0.1.0...0.2.0)

* Improved PHPUnit plugin
* Improved UI
* Added login by name (name or email)
* Fixed public build status page

## [PHP Censor v0.1.0](https://github.com/corpsee/php-censor/tree/0.1.0) (2017-01-04)

Initial release. Changes from PHPCI (1.7.1):

* Upped PHP minimal version from 5.3 to 5.6
* Fixed tests and other small fixes
* Redesigned project structure
* Added more debug info into the build log
* Moved CSS/JS dependencies from sources to Composer dependencies (asset-packagist.org)
* Added item per page parameter for build list

## PHP Censor v0 (2016-06-23)

Project started

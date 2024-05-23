# Danx Package

## Installation

```bash
composer require danx/laravel
```

## Setup

### Publish the configuration file

```bash
sail artisan vendor:publish --provider="Newms87\Danx\DanxServiceProvider"
```

## Development

### Install Danx UI

[Setup Danx UI](https://github.com/newms87/quasar-ui-danx)

#### Configure CORS

```bash
sail artisan config:publish cors
```

* Configure the `paths` so the desired routes are allowed
    * NOTE: By default it is open to all requests

### Symlink the Danx library

Symlinking the library will allow for realtime development of the danx library package. This is only useful for
development.
The command will symlink the vendor/newms87/danx package to the danx git repo that should be located in the same
directory as the project.

ie:

```text
- parent
  - danx
  - your-project
    - vendor
      - newms87
        - danx --> ../../../danx
```

If using docker, the danx library should be mounted to the docker container.

```yaml
services:
  laravel.test:
    volumes:
      - '../danx:/var/www/danx'
```

Run the command

```bash
sail artisan danx:link
```

## Publish package to composer

To publish packages, simply push a new tagged version to the repository.

```bash
make VERSION=1.0.0 publish
```

### Setup

#### Audit Logging

* Update `config/logging.php`

```php
'channels' => [
    
    //...
    
    'auditlog' => [
            'driver' => 'custom',
            'via'    => Newms87\Danx\Logging\Audit\AuditLogLogger::class,
            'level'  => env('LOG_LEVEL', 'debug'),
        ],
],
```

* Update `.env`
    * recommended to use `stack` as the main channel, so you can add additional logging channels

```dotenv
LOG_CHANNEL=stack
LOG_STACK={single},{other-log-channels},auditlog
```

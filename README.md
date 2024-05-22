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

```
sail artisan config:publish cors
```

* Configure the `paths` so the desired routes are allowed
    * NOTE: By default it is open to all requests

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

```
LOG_CHANNEL=stack
LOG_STACK={single},{other-log-channels},auditlog
```

<?php

use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

if (!defined('LARAVEL_START')) {
	define('LARAVEL_START', microtime(true));
}

/**
 * @template T
 * @param class-string<T> $class
 * @return T
 */
function app($class = null)
{
	return new $class;
}

function auth($guard = null): Factory|Guard|StatefulGuard { }

function config($path = ''): array|string|int|bool|float|Config { }

function request(): Request { }

function response($message, $httpStatusCode = 200): Response { }

function cache($key = null, $default = null): Cache { }

function database_path($path): string { }

function storage_path($path): string { }

function public_path($path): string { }

function resource_path($path): string { }

function base_path($path): string { }

function app_path($path): string { }

function config_path($path): string { }

function now(): Carbon { }

function route($name, $parameters = [], $absolute = true): string
{
	return app('url')->route($name, $parameters, $absolute);
}

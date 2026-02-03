<?php

namespace Newms87\Danx\Services\Error;

use Throwable;

/**
 * Checks if exceptions are retryable (transient failures).
 *
 * Supports two modes:
 * 1. Custom service class via config('errors.checker') - delegates entirely to that class
 * 2. Config-based rules via config('errors.retryable') - uses exception class => condition mapping
 *
 * For config caching compatibility, prefer using a custom service class (mode 1).
 */
class RetryableErrorChecker
{
    /**
     * Check if an exception is retryable.
     *
     * First checks for a custom checker service class in config('errors.checker').
     * If set, delegates entirely to that class's isRetryable() method.
     * Otherwise falls back to config-based rules in config('errors.retryable').
     */
    public static function isRetryable(Throwable $exception): bool
    {
        // Check for custom checker service (config-cache compatible)
        $checkerClass = config('errors.checker');
        if ($checkerClass && class_exists($checkerClass) && method_exists($checkerClass, 'isRetryable')) {
            return $checkerClass::isRetryable($exception);
        }

        // Fall back to config-based rules
        return static::checkConfigRules($exception);
    }

    /**
     * Check exception against config-based rules.
     *
     * Note: Using closures in config breaks config:cache. Prefer using
     * a custom checker service via config('errors.checker') instead.
     */
    protected static function checkConfigRules(Throwable $exception): bool
    {
        $retryableConfig = config('errors.retryable', []);

        foreach ($retryableConfig as $exceptionClass => $condition) {
            if (!$exception instanceof $exceptionClass) {
                continue;
            }

            // If set to true, always retryable
            if ($condition === true) {
                return true;
            }

            // If array of callbacks, check if ANY returns true
            if (is_array($condition)) {
                foreach ($condition as $callback) {
                    if (is_callable($callback) && $callback($exception)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

<?php

namespace Newms87\Danx\Services\Error;

use Throwable;

/**
 * Checks if exceptions are retryable (transient failures).
 *
 * Supports two checker types configured via danx config:
 * - api_retryable_checker: For API-level retries (retry loop in Api class)
 * - job_retryable_checker: For job-level retries (task process restarts)
 *
 * Each checker class must have a static isRetryable(Throwable): bool method.
 */
class RetryableErrorChecker
{
    /**
     * Check if an exception is retryable for API-level retries.
     *
     * Uses the checker class from config('danx.errors.api_retryable_checker').
     * Returns false if no checker is configured.
     */
    public static function isApiRetryable(Throwable $exception): bool
    {
        return static::checkWithChecker('api_retryable_checker', $exception);
    }

    /**
     * Check if an exception is retryable for job-level retries.
     *
     * Uses the checker class from config('danx.errors.job_retryable_checker').
     * Returns false if no checker is configured.
     */
    public static function isJobRetryable(Throwable $exception): bool
    {
        return static::checkWithChecker('job_retryable_checker', $exception);
    }

    /**
     * Check exception using a configured checker service class.
     */
    protected static function checkWithChecker(string $configKey, Throwable $exception): bool
    {
        $checkerClass = config("danx.errors.$configKey");

        if ($checkerClass && class_exists($checkerClass) && method_exists($checkerClass, 'isRetryable')) {
            return $checkerClass::isRetryable($exception);
        }

        return false;
    }
}

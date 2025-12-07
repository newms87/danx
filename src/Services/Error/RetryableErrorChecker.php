<?php

namespace Newms87\Danx\Services\Error;

use Throwable;

class RetryableErrorChecker
{
    /**
     * Check if an exception is retryable based on config/errors.php
     */
    public static function isRetryable(Throwable $exception): bool
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

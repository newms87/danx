<?php

namespace Newms87\Danx\Traits;

use Illuminate\Support\Facades\Log;

trait HasDebugLogging
{
    public static function logDebug(string $message, array $data = []): void
    {
        self::log('debug', $message, $data);
    }

    public static function logWarning(string $message, array $data = []): void
    {
        self::log('warning', $message, $data);
    }

    public static function logError(string $message, array $data = []): void
    {
        self::log('error', $message, $data);
    }

    private static function log(string $level, string $message, array $data = []): void
    {
        $loggerName = preg_replace('/.*\\\\/', '', static::class);

        if (empty($data)) {
            Log::$level("[$loggerName] $message");
        } else {
            $formattedMessage = "[$loggerName] $message\n" . json_encode($data, JSON_PRETTY_PRINT);
            Log::$level($formattedMessage);
        }
    }
}

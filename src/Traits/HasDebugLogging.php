<?php

namespace Newms87\Danx\Traits;

use Illuminate\Support\Facades\Log;

trait HasDebugLogging
{
    public static function logDebug(string $message, array $data = []): void
    {
        self::writeLog('debug', $message, $data);
    }

    public static function logWarning(string $message, array $data = []): void
    {
        self::writeLog('warning', $message, $data);
    }

    public static function logError(string $message, array $data = []): void
    {
        self::writeLog('error', $message, $data);
    }

    public static function logInfo(string $message, array $data = []): void
    {
        self::writeLog('info', $message, $data);
    }

    private static function writeLog(string $level, string $message, array $data = []): void
    {
        $loggerName = preg_replace('/.*\\\\/', '', static::class);
        $formattedMessage = "[$loggerName] $message";

        if (empty($data)) {
            Log::$level($formattedMessage);
        } else {
            // Extract exception if present - Laravel handles exceptions natively in context array
            $exception = null;
            if (isset($data['exception']) && $data['exception'] instanceof \Throwable) {
                $exception = $data['exception'];
                unset($data['exception']);
            }

            // Build context array for Laravel's logger
            $context = [];
            if ($exception) {
                $context['exception'] = $exception;
            }
            if (!empty($data)) {
                $formattedMessage .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
            }

            Log::$level($formattedMessage, $context);
        }
    }
}

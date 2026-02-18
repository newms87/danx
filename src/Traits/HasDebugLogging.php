<?php

namespace Newms87\Danx\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HasDebugLogging
{
    protected static bool  $traceEnabled   = false;
    protected static ?float $traceCheckedAt = null;

    public static function logDebug(string $message, array $data = []): void
    {
        self::writeLog('debug', $message, $data);
    }

    /**
     * Log a trace-level message. No-op when trace is disabled (fast path).
     * Trace state is read from cache at most every 10 seconds to avoid Redis roundtrips.
     */
    public static function logTrace(string $message, array $data = []): void
    {
        $now = microtime(true);

        if (static::$traceCheckedAt === null || ($now - static::$traceCheckedAt) > 10) {
            static::$traceEnabled   = (bool)Cache::get('debug:trace_enabled');
            static::$traceCheckedAt = $now;
        }

        if (!static::$traceEnabled) {
            return;
        }

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
                $formattedMessage .= self::formatData($data);
            }

            Log::$level($formattedMessage, $context);
        }
    }

    /**
     * Format data for log output. Small scalar-only payloads (<=3 keys) are inlined
     * as " (key=val, key2=val2)" to reduce log line count. Larger or complex payloads
     * use JSON_PRETTY_PRINT for readability.
     */
    private static function formatData(array $data): string
    {
        if (count($data) <= 3) {
            $allScalar = true;
            foreach ($data as $value) {
                if (!is_scalar($value) && $value !== null) {
                    $allScalar = false;
                    break;
                }
            }

            if ($allScalar) {
                $pairs = [];
                foreach ($data as $key => $value) {
                    if ($value === null) {
                        $display = 'null';
                    } elseif (is_bool($value)) {
                        $display = $value ? 'true' : 'false';
                    } else {
                        $display = $value;
                    }
                    $pairs[] = "$key=$display";
                }

                return ' (' . implode(', ', $pairs) . ')';
            }
        }

        return "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
}

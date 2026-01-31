<?php

namespace Newms87\Danx\Models\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Helpers\StringHelper;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ApiLog
 *
 * @mixin Builder
 */
class ApiLog extends Model
{
    use HasDebugLogging;

    protected $table = 'api_logs';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'request'          => 'json',
        'response'         => 'json',
        'request_headers'  => 'json',
        'response_headers' => 'json',
        'stack_trace'      => 'json',
        'started_at'       => 'datetime',
        'will_timeout_at'  => 'datetime',
    ];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    /**
     * Adds an API log entry to the database.
     */
    public static function logRequest(
        $apiClass,
        $serviceName,
        RequestInterface $request,
        ?int $timeoutSeconds = null
    ): ApiLog {
        $apiLog = ApiLog::create([
            'audit_request_id' => AuditDriver::getAuditRequest()?->id,
            'user_id'          => user()?->id,
            'api_class'        => $apiClass,
            'service_name'     => $serviceName,
            'url'              => substr($request->getUri(), 0, 512),
            'full_url'         => $request->getUri(),
            'status_code'      => null,
            'method'           => $request->getMethod(),
            'request'          => static::parseBody($request),
            'request_headers'  => $request->getHeaders(),
            'started_at'       => now(),
            'will_timeout_at'  => $timeoutSeconds ? now()->addSeconds($timeoutSeconds) : null,
        ]);

        $request->getBody()->rewind();

        return $apiLog;
    }

    /**
     * Adds an API response to an existing ApiLog entry in the database.
     */
    public static function logResponse(
        ApiLog $apiLog,
        ResponseInterface $response
    ): ApiLog {
        $runTimeMs = ($apiLog->started_at ?? now())->diffInMilliseconds(now());

        static::logDebug("Created $apiLog");

        $apiLog->update([
            'status_code'      => $response->getStatusCode(),
            'response'         => static::parseBody($response),
            'response_headers' => $response->getHeaders(),
            'stack_trace'      => $response->getStatusCode() >= 400 ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) : null,
            'finished_at'      => now(),
            'run_time_ms'      => $runTimeMs,
        ]);

        $response->getBody()->rewind();

        return $apiLog;
    }

    /**
     * Log API request completion (for any non-timeout error)
     * Ensures finished_at is always set for proper run_time_ms calculation
     */
    public static function logResponseError(ApiLog $apiLog, Throwable $exception, $errorType = 'request_error'): void
    {
        static::logError("Failed $apiLog: " . StringHelper::logSafeString($exception->getMessage()));

        $apiLog->update([
            'status_code' => method_exists($exception, 'getResponse') ? ($exception->getResponse()?->getStatusCode() ?? 0) : 0,
            'finished_at' => now(),
            'run_time_ms' => ($apiLog->started_at ?? now())->diffInMilliseconds(now()),
            'response'    => [
                'error_type'    => $errorType,
                'error_message' => $exception->getMessage(),
                'has_response'  => method_exists($exception, 'hasResponse') ? $exception->hasResponse() : false,
            ],
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
        ]);
    }

    /**
     * @return array|mixed|string|null
     */
    public static function parseBody($stream)
    {
        $maxBodyLength = config('danx.audit.api.max_body_length');

        if ($stream && method_exists($stream, 'getBody')) {
            $body       = (string)$stream->getBody();
            $bodyLength = strlen($body);

            if ($bodyLength === 0) {
                return null;
            }

            $bodyPreview = StringHelper::safeConvertToUTF8(substr($body, 0, $maxBodyLength));

            if ($bodyLength > $maxBodyLength) {
                return [
                    'message' => 'Body is too long',
                    'length'  => $bodyLength,
                    'preview' => $bodyPreview,
                ];
            }

            return StringHelper::safeJsonDecode($body) ?? [
                'message' => 'Failed to parse JSON body',
                'length'  => $bodyLength,
                'preview' => $bodyPreview,
            ];
        }

        return null;
    }

    /**
     * @return BelongsTo|AuditRequest
     */
    public function auditRequest()
    {
        return $this->belongsTo(AuditRequest::class);
    }

    public function __toString()
    {
        return "<ApiLog id='$this->id' $this->method $this->status_code $this->url>";
    }
}

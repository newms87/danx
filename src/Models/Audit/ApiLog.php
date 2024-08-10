<?php

namespace Newms87\Danx\Models\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Helpers\StringHelper;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ApiLog
 *
 * @mixin Builder
 */
class ApiLog extends Model
{
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
	];

	/**
	 * Adds an API log entry to the database.
	 */
	public static function logRequest(
		$apiClass,
		$serviceName,
		RequestInterface $request
	): ApiLog
	{
		$apiLog = ApiLog::create([
			'audit_request_id' => AuditDriver::getAuditRequest()?->id,
			'user_id'          => user()?->id,
			'api_class'        => $apiClass,
			'service_name'     => $serviceName,
			'url'              => substr($request->getUri(), 0, 512),
			'full_url'         => $request->getUri(),
			'status_code'      => 0,
			'method'           => $request->getMethod(),
			'request'          => static::parseBody($request),
			'request_headers'  => $request->getHeaders(),
		]);

		$request->getBody()->rewind();

		return $apiLog;
	}

	/**
	 * Adds an API response to an existing ApiLog entry in the database.
	 */
	public static function logResponse(
		ApiLog            $apiLog,
		ResponseInterface $response
	): ApiLog
	{
		$apiLog->update([
			'status_code'      => $response->getStatusCode(),
			'response'         => static::parseBody($response),
			'response_headers' => $response->getHeaders(),
			'stack_trace'      => $response->getStatusCode() >= 400 ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) : null,
		]);

		$response->getBody()->rewind();

		return $apiLog;
	}

	/**
	 * @param $stream
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
				'message' => "Failed to parse JSON body",
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
		$request  = json_encode($this->request);
		$response = json_encode($this->response);

		return "$this->method $this->status_code $this->url\n\nRequest:\n$request\n\nResponse:\n$response";
	}
}

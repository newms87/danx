<?php

namespace Newms87\DanxLaravel\Middleware;

use Closure;
use Exception;
use Newms87\DanxLaravel\Audit\AuditDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;

class ResponseSizeLimitMiddleware
{
	public function handle($request, Closure $next)
	{
		$response = $next($request);

		// AWS ALB Lambda responses have a hard 6MB limit on payload size (including headers / requests / responses)
		// If this limit is reached, we will send a message to slack to inform us of an issue.
		if (config('danx.response_size_limit.enabled')) {
			$content = method_exists($response, 'content') ? $response->content() : $response->getContent();

			$payloadSize = strlen($content) ?? 0;

			if ($payloadSize > config('danx.response_size_limit.limit')) {
				return static::convertResponseToFile($content, $payloadSize, $response);
			}
		}

		return $response;
	}

	/**
	 * This is a hack to get around the hard 1MB limit for Lambda responses via ALB on AWS.
	 * We are storing the response in a publicly accessible file (ie: a s3 bucket) and then returning
	 * a redirect to that file for the browser to download as the response
	 *
	 * @param              $content
	 * @param              $payloadSize
	 * @param              $response
	 * @return RedirectResponse
	 * @throws Exception
	 */
	public static function convertResponseToFile($content, $payloadSize, $response)
	{
		$auditRequest = AuditDriver::getAuditRequest();

		if ($auditRequest) {
			$url    = app_url('nova/resources/audit-requests/' . $auditRequest->id);
			$arLink = "<$url|Request $auditRequest->id ($payloadSize Bytes)>";
		} else {
			$arLink = "Request ($payloadSize Bytes)";
		}

		// Make sure we log this s3 workaround issue, so we can more easily debug issues
		Log::info("AWS Lambda payload limit reached: $arLink");

		$disk = Storage::disk(config('danx.response_size_limit.disk'));
		$path = "lambda-responses/$auditRequest->id.txt";
		$disk->put($path, $content, 'public');
		$s3Url = $disk->url($path);

		return Redirect::away(self::rewriteCdnUrl($s3Url), 303, $response->headers->all());
	}

	/**
	 * Rewrite the URL to use a CDN if enabled
	 *
	 * @param string $url
	 * @return string
	 */
	private static function rewriteCdnUrl(string $url): string
	{
		$origin = config('danx.response_size_limit.cdn_origin');
		$alias  = config('danx.response_size_limit.cdn_alias');

		if ($alias) {
			return str_replace(
				$origin,
				$alias,
				$url
			);
		}

		return $url;
	}
}

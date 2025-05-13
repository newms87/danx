<?php

namespace Newms87\Danx\Middleware;

use Closure;
use Illuminate\Http\Request;

class AppVersionMiddleware
{
	public function handle(Request $request, Closure $next)
	{
		$response = $next($request);

		if (config('app.version') && method_exists($response, 'header')) {
			$response->header('X-App-Version', config('app.version'));

			// Append to Access-Control-Expose-Headers to expose the custom header
			$exposeHeaders = $response->headers->get('Access-Control-Expose-Headers');
			if ($exposeHeaders) {
				$response->header('Access-Control-Expose-Headers', $exposeHeaders . ', X-App-Version');
			} else {
				$response->header('Access-Control-Expose-Headers', 'X-App-Version');
			}
		}

		return $response;
	}
}

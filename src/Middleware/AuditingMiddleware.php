<?php

namespace Newms87\Danx\Middleware;

use Closure;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Models\Audit\ErrorLog;
use Illuminate\Http\Request;
use Throwable;

class AuditingMiddleware
{
	public function handle(Request $request, Closure $next)
	{
		if ($request->method() === 'OPTIONS') {
			return $next($request);
		}

		AuditDriver::startTimer();

		try {
			$response = $next($request);
		} catch(Throwable $throwable) {
			ErrorLog::logException('ERROR', $throwable);
			$response = response([
				'error'   => true,
				'message' => 'An error occurred. Please try again later.',
				'context' => 'AuditingMiddleware@handle',
			], 500);
		}

		return AuditDriver::terminate($response);
	}
}

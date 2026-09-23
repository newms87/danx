<?php

namespace Newms87\Danx\Middleware;

use Closure;
use Illuminate\Http\Request;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Models\Audit\ErrorLog;
use Throwable;

class AuditingMiddleware
{
	/**
	 * Every request starts with no audit request of its own; the first thing it logs creates one.
	 *
	 * Why the release (SG-858): {@see AuditDriver::$auditRequest} is a process-lifetime static,
	 * and under Octane one worker process serves request after request. Nothing else releases it
	 * between them, so every request a worker served was attributed to the audit request of the
	 * first one it logged anything for (observed locally on Swoole: six consecutive requests all
	 * answered `X-Audit-Request-Id: 386808`, a row created for an earlier `/api/pusher/...`
	 * call). If that row was deleted meanwhile, every later write in the worker was rejected by
	 * its foreign key. A request is a unit of work exactly as a queued job is, and is released at
	 * its start the same way ({@see \Newms87\Danx\Listeners\ReleaseAuditRequestAtJobBoundary}).
	 * Only the start: anything that runs after the response (terminable middleware) still
	 * belongs to this request.
	 */
	public function handle(Request $request, Closure $next)
	{
		AuditDriver::releaseAuditRequest();

		if ($request->method() === 'OPTIONS') {
			return $next($request);
		}

		AuditDriver::startTimer();

		try {
			$response = $next($request);
		} catch(Throwable $throwable) {
			ErrorLog::logException(ErrorLog::ERROR, $throwable);
			$response = response([
				'error'   => true,
				'message' => 'An error occurred. Please try again later.',
				'context' => 'AuditingMiddleware@handle',
			], 500);
		}

		return AuditDriver::terminate($response);
	}
}

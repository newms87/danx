<?php

namespace Newms87\Danx\Exceptions;

/**
 * Thrown by Api::call()'s fork+SIGKILL-based hard backstop when an outbound HTTP
 * request has run longer than its configured client-side timeout (plus a small
 * buffer) WITHOUT curl's own timeout mechanism (CURLOPT_TIMEOUT / Guzzle's
 * 'timeout' option) aborting it first.
 *
 * Real, repeated production incident this exists to close: an outbound OpenAI call
 * (POST https://api.openai.com/v1/responses) can hang indefinitely with zero bytes
 * back and zero exception thrown, even though a client-side timeout is correctly
 * configured and correctly wired down to CURLOPT_TIMEOUT_MS. The mechanism by which
 * curl's own timeout fails to fire is not fully understood (leading theory: real
 * CPU/memory contention under heavy concurrent production load defeats curl's
 * internal timer, possibly via signal-delivery delay or process descheduling at
 * exactly the wrong moment) — this exception, and the fork+SIGKILL backstop that
 * throws it (see Api::requestWithTimeoutBackstop() for why an in-process
 * pcntl_alarm()+SIGALRM handler was tried first and empirically does NOT work
 * against a genuinely-hung blocking curl call), are deliberately mechanism-agnostic:
 * an external watchdog process enforces the deadline via SIGKILL, which the kernel
 * honors unconditionally regardless of what the target is blocked in, so recovery
 * does not depend on understanding why curl's own mechanism failed.
 *
 * This is intentionally NOT a subtype of GuzzleHttp's RequestException or
 * ConnectException — it is not a Guzzle-layer failure, it is a signal interrupting
 * whatever PHP/curl was doing. Api::call() catches it alongside those two in its
 * retry loop and classifies/logs it the same way a timeout is classified.
 *
 * Retryability: a signal-interrupted hung call is exactly the "transient, safe to
 * retry" case — same reasoning as this app's existing ConnectException / LockException
 * classifiers. See App\Services\Error\RetryableApiExceptionService::isRetryable()
 * (API-level retry, danx's own Api::call() retry loop) and
 * App\Services\Error\RetryableJobExceptionService::isRetryableApiTimeoutError()
 * (job-level retry) in the consuming application — both must classify this
 * exception as retryable for the backstop to compose with the app's existing
 * retry infrastructure instead of hard-failing a job outright.
 */
class ApiTimeoutBackstopException extends ApiException
{
    public function __construct(string $serviceName, string $method, string $url, int $armedSeconds)
    {
        parent::__construct(
            "{$serviceName} API request ({$method} {$url}) exceeded its {$armedSeconds}s fork+SIGKILL timeout " .
            'backstop — curl\'s own client-side timeout did not fire in time, so an external watchdog process ' .
            'force-interrupted the hung request instead.',
            0
        );
    }
}

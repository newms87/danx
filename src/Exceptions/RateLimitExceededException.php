<?php

namespace Newms87\Danx\Exceptions;

/**
 * Thrown by Api::throttle() when a rate-limited service's request budget stays
 * exhausted past the configured max wait bound. Carries retryAfterSeconds (the
 * limiter key's remaining Redis TTL) so callers/queue infra can schedule an
 * informed retry instead of busy-waiting inline.
 */
class RateLimitExceededException extends ApiException
{
	public function __construct(
		string $message,
		public readonly int $retryAfterSeconds,
		$previous = null
	)
	{
		parent::__construct($message, 1001, $previous);
	}
}

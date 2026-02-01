<?php

namespace Newms87\Danx\Exceptions;

use Exception;

/**
 * Exception thrown when a lock request is stale (outdated).
 *
 * Used for deduplicating broadcast events. When multiple events are queued
 * for the same model, and a newer event has already been sent, older events
 * should throw this exception to indicate they should be silently dropped.
 */
class StaleLockException extends Exception
{
    public function __construct(
        protected string $key,
        protected string $ourTimestamp,
        protected string $lockedTimestamp
    ) {
        parent::__construct(
            "Stale lock request for $key: our timestamp ($ourTimestamp) < lock timestamp ($lockedTimestamp)"
        );
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getOurTimestamp(): string
    {
        return $this->ourTimestamp;
    }

    public function getLockedTimestamp(): string
    {
        return $this->lockedTimestamp;
    }
}

<?php

namespace Newms87\Danx\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Exceptions\StaleLockException;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Traits\BroadcastsWithSubscriptions;
use Newms87\Danx\Traits\HasDebugLogging;

/**
 * Base class for model update events with Pusher broadcasting
 *
 * This class provides subscription-based broadcasting for team-scoped models.
 *
 * Usage:
 * - Pass the Resource class name to derive the resource type automatically
 * - Pass teamId in constructor for simple cases
 * - Override getTeamId() for complex team resolution logic
 * - broadcastOn() is implemented automatically using subscription system
 */
abstract class ModelSavedEvent implements ShouldBroadcast
{
    use BroadcastsWithSubscriptions, Dispatchable, HasDebugLogging, InteractsWithSockets;

    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DELETED = 'deleted';

    /**
     * Tracks which model instances have already broadcasted a 'created' event.
     * Keyed by model class and ID to prevent setting attributes on the model.
     *
     * @var array<string, bool>
     */
    protected static array $broadcastedCreateCache = [];

    /**
     * Max seconds to wait for lock acquisition during deduplication.
     * Override in subclass if needed.
     */
    protected int $lockWaitTime = 5;

    /**
     * Polling interval in milliseconds when waiting for lock.
     * Override in subclass if needed.
     */
    protected int $lockPollMs = 100;

    /**
     * Lock TTL in seconds. Lock auto-expires after this time.
     * Override in subclass if needed.
     */
    protected int $lockTtl = 60;

    /**
     * Audit request ID captured at event construction time for traceability.
     * This identifies which request/job initiated the broadcast.
     */
    protected ?int $auditRequestId;

    /**
     * Timestamp when the broadcast was initiated (not when actually sent).
     * Captured at construction time to track timing even when queued.
     */
    protected string $broadcastedAt;

    /**
     * Stored model references for serialization.
     * Maps property name to [class, id, attributes] for restoration.
     *
     * @var array<string, array{class: string, id: mixed, attributes: array}>
     */
    protected array $serializedModels = [];

    /**
     * @param  Model  $model  The model instance (e.g., WorkflowRun, TaskRun)
     * @param  string  $event  The event name (e.g., 'updated', 'created')
     * @param  string|null  $resourceClass  The Resource class (e.g., WorkflowRunResource::class)
     * @param  int|null  $teamId  The team ID (optional - override getTeamId() if complex)
     */
    public function __construct(protected Model $model, protected string $event, protected ?string $resourceClass = null, protected ?int $teamId = null)
    {
        // Capture traceability data at construction time (before queuing)
        $this->auditRequestId = AuditDriver::getAuditRequest()?->id;
        $this->broadcastedAt = now()->toIso8601String();
    }

    /**
     * Custom serialization - convert Model properties to class/id/attributes references.
     * This avoids serializing entire model objects while preserving data for restoration.
     */
    public function __serialize(): array
    {
        $data = [];
        $serializedModels = [];

        foreach (get_object_vars($this) as $key => $value) {
            if ($value instanceof Model) {
                // Store model reference for restoration
                $serializedModels[$key] = [
                    'class'      => get_class($value),
                    'id'         => $value->getKey(),
                    'attributes' => $value->getAttributes(),
                ];
                $data[$key] = null; // Don't serialize the model object
            } else {
                $data[$key] = $value;
            }
        }

        // Include serializedModels in the serialized data
        $data['serializedModels'] = $serializedModels;

        return $data;
    }

    /**
     * Custom unserialization - restore Model properties from DB (with trashed support).
     * Falls back to stored attributes if model not found in DB (hard deleted).
     */
    public function __unserialize(array $data): void
    {
        // Extract serializedModels first
        $serializedModels = $data['serializedModels'] ?? [];
        unset($data['serializedModels']);

        // Restore all non-model properties (skip model placeholders)
        foreach ($data as $key => $value) {
            if (!isset($serializedModels[$key])) {
                $this->$key = $value;
            }
        }

        // Restore model properties from DB or attributes
        foreach ($serializedModels as $key => $modelData) {
            $this->$key = $this->restoreModelFromData($modelData);
        }
    }

    /**
     * Restore a model from serialized data.
     * Uses withTrashed() for soft-delete support, falls back to attributes if not found.
     */
    protected function restoreModelFromData(array $modelData): Model
    {
        $class = $modelData['class'];
        $id = $modelData['id'];
        $attributes = $modelData['attributes'];

        // Try to fetch from DB (with trashed for soft-delete support)
        $query = $class::query();
        if (method_exists($class, 'withTrashed')) {
            $query->withTrashed();
        }

        $model = $query->find($id);

        if ($model) {
            return $model;
        }

        // Model not in DB (hard deleted) - restore from stored attributes
        $model = new $class();
        $model->setRawAttributes($attributes, true);
        $model->exists = false;

        return $model;
    }

    /**
     * Get the cache lock key for a model instance
     */
    public static function lockKey(Model $model): string
    {
        return 'model-saved:' . $model->getTable() . ':' . $model->getKey();
    }

    /**
     * Get the lock key for this event instance
     */
    protected function getLockKey(): string
    {
        return static::lockKey($this->model);
    }

    /**
     * Determine the event type based on model state.
     *
     * For the same model instance, the first call returns 'created' if wasRecentlyCreated is true,
     * and subsequent calls return 'updated'. This prevents multiple 'created' events when a model
     * is saved multiple times within the same request/job.
     */
    public static function getEvent(Model $model): string
    {
        if ($model->wasRecentlyCreated) {
            // Check if we've already broadcasted 'created' for this instance
            if (static::hasBroadcastedCreate($model)) {
                return self::EVENT_UPDATED;
            }

            return self::EVENT_CREATED;
        } elseif ($model->exists) {
            return self::EVENT_UPDATED;
        }

        return self::EVENT_DELETED;
    }

    /**
     * Check if a model has already broadcasted a 'created' event.
     */
    protected static function hasBroadcastedCreate(Model $model): bool
    {
        $key = get_class($model) . ':' . $model->getKey();

        return static::$broadcastedCreateCache[$key] ?? false;
    }

    /**
     * Mark the model as having broadcasted a 'created' event.
     *
     * Call this after broadcasting a 'created' event to ensure subsequent
     * saves on the same instance emit 'updated' instead of 'created'.
     */
    public static function markCreatedBroadcast(Model $model): void
    {
        $key = get_class($model) . ':' . $model->getKey();
        static::$broadcastedCreateCache[$key] = true;
    }

    /**
     * Broadcast the event for a model
     *
     * @param  Model  $model  The model instance
     * @param  string|null  $event  Optional event type override ('created', 'updated', 'deleted')
     */
    public static function broadcast(Model $model, ?string $event = null): void
    {
        $eventType = $event ?? static::getEvent($model);
        broadcast(new static($model, $eventType));

        // Mark 'created' as broadcasted so subsequent saves emit 'updated'
        if ($eventType === self::EVENT_CREATED) {
            static::markCreatedBroadcast($model);
        }
    }

    /**
     * Override Dispatchable trait's dispatch() to redirect to broadcast().
     * This ensures any lingering dispatch() calls still work correctly.
     *
     * @param  Model  $model  The model instance
     * @param  string|null  $event  Optional event type override
     */
    public static function dispatch(Model $model, ?string $event = null): void
    {
        static::broadcast($model, $event);
    }

    /**
     * Determine which channels to broadcast on.
     *
     * Uses subscription system to find subscribed users. Does a quick stale check
     * (without locking) to skip obviously stale events. Full deduplication with
     * locking happens in broadcastWith().
     */
    public function broadcastOn()
    {
        // Quick stale check - if an event was already sent after this one was created, skip
        if (LockHelper::isStaleTimestamp($this->getLockKey(), $this->broadcastedAt)) {
            static::logDebug("Stale event skipped in broadcastOn: {$this->getLockKey()} (ts={$this->broadcastedAt})");

            return [];
        }

        $resourceType = $this->getResourceType();
        $teamId = $this->getTeamId();

        $userIds = $this->getSubscribedUsers(
            $resourceType,
            $teamId,
            $this->model,
            get_class($this->model)
        );

        return $this->getSubscribedChannels($resourceType, $teamId, $userIds);
    }

    /**
     * Get the event name for broadcasting
     */
    public function broadcastAs()
    {
        return $this->event;
    }

    /**
     * Get the data to broadcast with the event.
     *
     * Acquires a timestamped lock for deduplication, refreshes model for latest data,
     * and releases lock in finally block. If this event is stale (a newer event is
     * being sent or was already sent), returns minimal skip payload.
     */
    public function broadcastWith()
    {
        $lockKey = $this->getLockKey();

        // Acquire lock for deduplication - ensures only freshest event broadcasts
        try {
            LockHelper::acquireWithTimestamp(
                $lockKey,
                $this->broadcastedAt,
                waitTime: $this->lockWaitTime,
                pollMs: $this->lockPollMs,
                ttl: $this->lockTtl
            );
        } catch (StaleLockException $e) {
            // This event is stale - a newer event has already been sent or is being sent
            static::logDebug("Stale event skipped in broadcastWith: $lockKey (ts={$this->broadcastedAt})");

            // Return minimal payload - event still fires but frontend should ignore
            return ['__stale' => true, 'id' => $this->model->getKey(), '__model' => get_class($this->model)];
        }

        try {
            // Refresh model to get latest data at broadcast time (skip for deleted models)
            if ($this->event !== self::EVENT_DELETED) {
                $this->model->refresh();
            }

            $data = $this->data();

            // Include the user who triggered this event so frontend can filter out own events
            $data['triggered_by_user_id'] = auth()->id();

            // Include traceability data captured at construction time
            $data['__audit_request_id'] = $this->auditRequestId;
            $data['__broadcasted_at'] = $this->broadcastedAt;

            return $data;
        } finally {
            // Always release the lock
            LockHelper::releaseWithTimestamp($lockKey);
        }
    }

    /**
     * Get broadcast payload - automatically calls createdData(), updatedData(), or deletedData() based on event type
     */
    public function data(): array
    {
        return match ($this->event) {
            self::EVENT_CREATED => $this->createdData(),
            self::EVENT_UPDATED => $this->updatedData(),
            self::EVENT_DELETED => $this->deletedData(),
            default             => $this->updatedData(),
        };
    }

    /**
     * Data to broadcast on 'deleted' events.
     * Override this method to customize delete payloads.
     * Default: ID, resource type, and deleted_at timestamp (model no longer exists in DB).
     */
    protected function deletedData(): array
    {
        return [
            'id'         => $this->model->getKey(),
            '__type'     => $this->getResourceType(),
            'deleted_at' => $this->broadcastedAt,
        ];
    }

    /**
     * Data to broadcast on 'created' events
     * Override this method to customize create payloads
     * Default: Full resource data
     */
    protected function createdData(): array
    {
        if (!$this->resourceClass) {
            return [];
        }

        return $this->resourceClass::make($this->model);
    }

    /**
     * Data to broadcast on 'updated' events
     * Override this method to customize update payloads (typically smaller subset)
     * Default: Full resource data
     */
    protected function updatedData(): array
    {
        if (!$this->resourceClass) {
            return [];
        }

        return $this->resourceClass::make($this->model);
    }

    /**
     * Extract resource type from Resource class name
     * Example: AgentThreadRunResource -> AgentThreadRun
     */
    protected function getResourceType(): string
    {
        if (!isset($this->resourceClass)) {
            return '';
        }

        $className = basename(str_replace('\\', '/', $this->resourceClass));

        return str_replace('Resource', '', $className);
    }

    /**
     * Get the team ID for this event
     * Override this method for complex team resolution logic
     */
    protected function getTeamId(): ?int
    {
        return $this->teamId;
    }
}

<?php

namespace Newms87\Danx\Events;

use App\Traits\BroadcastsWithSubscriptions;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

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
	use Dispatchable, InteractsWithSockets, SerializesModels, BroadcastsWithSubscriptions;

	/**
	 * @param Model       $model         The model instance (e.g., WorkflowRun, TaskRun)
	 * @param string      $event         The event name (e.g., 'updated', 'created')
	 * @param string|null $resourceClass The Resource class (e.g., WorkflowRunResource::class)
	 * @param int|null    $teamId        The team ID (optional - override getTeamId() if complex)
	 */
	public function __construct(protected Model $model, protected string $event, protected ?string $resourceClass = null, protected ?int $teamId = null)
	{
	}

	/**
	 * Get the cache lock key for this model
	 */
	public static function lockKey(Model $model): string
	{
		return 'model-saved:' . $model->getTable() . ':' . $model->getKey();
	}

	/**
	 * Determine the event type based on model state
	 */
	public static function getEvent(Model $model): string
	{
		if ($model->wasRecentlyCreated) {
			return 'created';
		} elseif ($model->exists) {
			return 'updated';
		}

		return 'deleted';
	}

	/**
	 * Broadcast the event for a model
	 */
	public static function broadcast(Model $model): void
	{
		broadcast(new static($model, static::getEvent($model)));
	}

	/**
	 * Dispatch the event with a lock to prevent duplicate broadcasts
	 */
	public static function dispatch(Model $model): void
	{
		$lock = Cache::lock(static::lockKey($model), 5);

		if ($lock->get()) {
			event(new static($model, static::getEvent($model)));
		}
	}

	/**
	 * Determine which channels to broadcast on
	 * Uses subscription system to find subscribed users
	 */
	public function broadcastOn()
	{
		$userIds = $this->getSubscribedUsers(
			$this->getResourceType(),
			$this->getTeamId(),
			$this->model,
			get_class($this->model)
		);

		return $this->getSubscribedChannels($this->getResourceType(), $this->getTeamId(), $userIds);
	}

	/**
	 * Get the event name for broadcasting
	 */
	public function broadcastAs()
	{
		return $this->event;
	}

	/**
	 * Get the data to broadcast with the event
	 */
	public function broadcastWith()
	{
		$data = $this->data();
		Cache::lock(static::lockKey($this->model))->forceRelease();

		return $data;
	}

	/**
	 * Get broadcast payload - automatically calls createdData() or updatedData() based on event type
	 */
	public function data(): array
	{
		return match ($this->event) {
			'created' => $this->createdData(),
			'updated' => $this->updatedData(),
			default => $this->updatedData(),
		};
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

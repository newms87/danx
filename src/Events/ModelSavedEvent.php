<?php

namespace Newms87\Danx\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

abstract class ModelSavedEvent implements ShouldBroadcast
{
	use Dispatchable, InteractsWithSockets, SerializesModels;

	public function __construct(protected Model $model, protected string $event) { }

	public static function lockKey(Model $model): string
	{
		return 'model-saved:' . $model->getTable() . ':' . $model->getKey();
	}

	public static function getEvent(Model $model): string
	{
		if ($model->wasRecentlyCreated) {
			return 'created';
		} elseif ($model->exists) {
			return 'updated';
		}

		return 'deleted';
	}

	public static function broadcast(Model $model): void
	{
		broadcast(new static($model, static::getEvent($model)));
	}

	public static function dispatch(Model $model): void
	{
		$lock = Cache::lock(static::lockKey($model), 5);

		if ($lock->get()) {
			event(new static($model, static::getEvent($model)));
		}
	}

	abstract public function broadcastOn();

	public function broadcastAs()
	{
		return $this->event;
	}

	abstract public function data(): array;

	public function broadcastWith()
	{
		$data = $this->data();
		Cache::lock(static::lockKey($this->model))->forceRelease();

		return $data;
	}
}

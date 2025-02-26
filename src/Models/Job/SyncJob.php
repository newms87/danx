<?php

namespace Newms87\Danx\Models\Job;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\Job;
use Throwable;

class SyncJob extends Model
{
	protected $table = 'sync_jobs';

	protected $guarded = ['id'];

	protected $casts = [
		'synced_at' => 'datetime',
		'dirty_at'  => 'datetime',
		'retry_at'  => 'datetime',
	];

	public static array $recentJobs = [];

	public function syncModel()
	{
		return $this->morphTo('model', 'model', 'model_id');
	}

	public function jobDispatches(): MorphToMany
	{
		return $this->morphToMany(JobDispatch::class, 'model', 'job_dispatchables');
	}

	public function getIsSyncedAttribute(): bool
	{
		return $this->synced_at !== null;
	}

	public function getSyncedStatusAttribute(): string
	{
		if (!$this->dirty_at && !$this->synced_at) {
			return 'N/A';
		}

		return $this->is_synced ? 'Synced' : 'Not Synced';
	}

	public function getNovaUrlAttribute()
	{
		return api_url('nova/resources/sync-jobs/' . $this->id);
	}

	/**
	 * Resolves a sync job based on the name and model.
	 */
	public static function resolveSyncJob($name, Model $model)
	{
		$syncJobKey = 'sync-job-create-' . $model::class . ':' . $model->id . ':' . $name;
		LockHelper::acquire($syncJobKey);
		$syncJob = SyncJob::firstOrCreate([
			'model'    => $model::class,
			'model_id' => $model->id,
			'name'     => $name,
		]);
		LockHelper::release($syncJobKey);

		return $syncJob;
	}

	/**
	 * Syncs a model with a given sync job name.
	 * This will resolve a unique sync job based on the name and model and run a callback to sync the model.
	 * If the callback returns true (or null), the sync job will be marked as successful.
	 * If the callback returns false, the sync job will not be marked as successful.
	 */
	public static function sync($name, Model $model, $callback)
	{
		return static::resolveSyncJob($name, $model)->markDirty($callback);
	}

	/**
	 * Compares a model's dirty_at timestamp to the last synced_at timestamp for a given sync job.
	 * If the model is dirty, it will sync the job.
	 */
	public static function syncIfDirty($name, Model $model, Carbon $dirtyAt, $callback): SyncJob
	{
		$syncJob = static::resolveSyncJob($name, $model);

		if (!$syncJob?->synced_at || ($syncJob->synced_at < $dirtyAt)) {
			return $syncJob->markDirty($callback);
		}

		return $syncJob;
	}

	/**
	 * Run the sync job defined by the name and Model
	 */
	public static function runFor($name, Model $model)
	{
		$syncJob = SyncJob::firstWhere([
			'model'    => $model::class,
			'model_id' => $model->id,
			'name'     => $name,
		]);

		if (!$syncJob) {
			throw new Exception("Sync job not found for $name on $model->id");
		}
		$syncJob->run();
	}

	/**
	 * Marks a sync job as dirty and resets the synced_at timestamp.
	 */
	public function markDirty($callback): SyncJob
	{
		LockHelper::acquire($this);
		try {
			$this->callback  = serialize(new SerializableClosure($callback));
			$this->dirty_at  = now();
			$this->attempts  = 0;
			$this->retry_at  = null;
			$this->synced_at = null;
			$this->save();
		} finally {
			LockHelper::release($this);
		}

		static::$recentJobs[] = $this;

		return $this;
	}

	/**
	 * Marks a sync job as successful and resets the dirty_at and retry_at timestamps.
	 */
	public function syncSuccessful()
	{
		$this->dirty_at  = null;
		$this->retry_at  = null;
		$this->synced_at = now();
		$this->save();
	}

	public function getModelInstance()
	{
		return $this->model::find($this->model_id);
	}

	/**
	 * Run any jobs queued up in this request synchronously.
	 * Optionally pass in a limit to only run a certain number of the most recent jobs.
	 * A limit of -1 will run all jobs recently queued.
	 */
	public static function runRecentNow($limit = -1)
	{
		foreach(array_reverse(static::$recentJobs) as $syncJob) {
			if ($limit-- === 0) {
				break;
			}
			$syncJob->run();
		}
	}

	/**
	 * @return void
	 * @throws Throwable
	 */
	public function run()
	{
		if (!$this->callback) {
			return;
		}

		$callback = unserialize($this->callback);

		try {
			LockHelper::acquire($this);

			if (Job::$runningJob) {
				$this->jobDispatches()->syncWithoutDetaching([Job::$runningJob->id]);
			}

			if (!$this->dirty_at || $this->synced_at) {
				$this->dirty_at  = now();
				$this->synced_at = null;
			}

			$this->save();

			$instance = $this->getModelInstance();

			Log::debug("Sync Job running: $this->name for $this->model ($this->id)" . ($instance ? "" : " MODEL NOT FOUND"));

			if (!$instance) {
				Log::warning("Sync Job skipped: Model not found. Maybe it was deleted?");
				$this->dirty_at = null;
				$this->retry_at = null;
				$this->save();

				return;
			}

			// If the callback returns false, we don't want to mark the sync as successful (this could mean the job is running asynchronously)
			if ($callback($instance, $this) === false) {
				return;
			}

			Log::debug("Sync Job successful: $this->name for $this->model ($this->id)");

			$this->syncSuccessful();
		} catch(Throwable $e) {
			Log::warning("SyncJob failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
			throw $e;
		} finally {
			LockHelper::release($this);
		}
	}
}

<?php

namespace Newms87\Danx\Models\Job;

use Closure;
use Exception;
use Newms87\Danx\Helpers\LockHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class SyncJob extends Model
{
	protected $table = 'sync_jobs';

	protected $guarded = ['id'];

	protected $casts = [
		'synced_at' => 'datetime',
		'dirty_at'  => 'datetime',
	];

	/**
	 * @return BelongsTo|JobDispatch
	 */
	public function jobDispatch()
	{
		return $this->belongsTo(JobDispatch::class);
	}

	/**
	 * @param         $name
	 * @param Model   $model
	 * @param Closure $callback
	 * @return SyncJob
	 * @throws PhpVersionNotSupportedException
	 * @throws Throwable
	 */
	public static function sync($name, Model $model, $callback)
	{
		$syncJob = SyncJob::firstOrCreate([
			'model'    => $model::class,
			'model_id' => $model->id,
			'name'     => $name,
		]);

		LockHelper::acquire($syncJob);
		$syncJob->callback  = serialize(new SerializableClosure($callback));
		$syncJob->dirty_at  = now();
		$syncJob->attempts  = 0;
		$syncJob->synced_at = null;
		$syncJob->save();
		LockHelper::release($syncJob);

		return $syncJob;
	}

	/**
	 * @param       $name
	 * @param Model $model
	 * @return void
	 * @throws Throwable
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

	public function syncSuccessful()
	{
		$this->dirty_at  = null;
		$this->synced_at = now();
		$this->save();
	}

	public function getModelInstance()
	{
		return $this->model::find($this->model_id);
	}

	/**
	 * @return void
	 * @throws Throwable
	 */
	public function run()
	{
		if ($this->callback) {
			$callback = unserialize($this->callback);

			try {
				LockHelper::acquire($this);

				$instance = $this->getModelInstance();

				Log::debug("Sync Job running: $this->name for $this->model ($this->id)" . ($instance ? "" : " MODEL NOT FOUND"));

				// If the callback returns false, we don't want to mark the sync as successful or release the lock (this could mean the job is running asynchronously)
				if ($callback($this, $instance) === false) {
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
}

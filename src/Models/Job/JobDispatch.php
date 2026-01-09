<?php

namespace Newms87\Danx\Models\Job;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Models\Audit\AuditRequest;

class JobDispatch extends Model
{
	const string
		STATUS_PENDING = 'Pending',
		STATUS_RUNNING = 'Running',
		STATUS_COMPLETE = 'Complete',
		STATUS_EXCEPTION = 'Exception',
		STATUS_ABORTED = 'Aborted',
		STATUS_FAILED = 'Failed',
		STATUS_TIMEOUT = 'Timeout';

	protected $fillable = [
		'user_id',
		'team_id',
		'status',
		'ref',
		'name',
		'count',
		'ran_at',
		'completed_at',
		'timeout_at',
		'data',
		'running_audit_request_id',
		'dispatch_audit_request_id',
	];

	protected $table = 'job_dispatch';

	protected $casts = [
		'ran_at'       => 'datetime',
		'completed_at' => 'datetime',
		'timeout_at'   => 'datetime',
		'data'         => 'json',
	];

	public function user(): BelongsTo
	{
		return $this->belongsTo(config('auth.providers.users.model'));
	}

	public static function pendingJob(string $ref): JobDispatch|null
	{
		return self::where('ref', $ref)
			->where('status', self::STATUS_PENDING)
			->first();
	}

	public static function runningJob(string $ref): JobDispatch|null
	{
		return self::where('ref', $ref)
			->where('status', self::STATUS_RUNNING)
			->first();
	}

	/**
	 * Checks if the job has past its defined time out period
	 */
	public function isTimedOut(): bool
	{
		if ($this->timeout_at) {
			return $this->timeout_at->isPast();
		}

		return $this->created_at?->addSeconds(90)->isPast() ?? false;
	}

	public function jobBatch(): BelongsTo|JobBatch
	{
		return $this->belongsTo(JobBatch::class, 'job_batch_id');
	}

	public function runningAuditRequest(): BelongsTo|AuditRequest
	{
		return $this->belongsTo(AuditRequest::class, 'running_audit_request_id');
	}

	public function dispatchAuditRequest(): BelongsTo|AuditRequest
	{
		return $this->belongsTo(AuditRequest::class, 'dispatch_audit_request_id');
	}

	public function team(): BelongsTo
	{
		$teamClass = config('danx.models.team', \Newms87\Danx\Models\Team\Team::class);

		return $this->belongsTo($teamClass);
	}

	public static function incrementCount(string $ref): void
	{
		JobDispatch::where('ref', $ref)
			->where('status', JobDispatch::STATUS_PENDING)
			->increment('count');
	}

	public function __toString(): string
	{
		$createdAt = DateHelper::formatDateTime($this->created_at);

		return "<JobDispatch $this->id ($this->ref) status='$this->status' count='$this->count' tag='$this->job_tag' created='$createdAt'>";
	}

	public static function booted()
	{
		static::saving(function (JobDispatch $jobDispatch) {
			if ($jobDispatch->isDirty(['completed_at', 'ran_at'])) {
				$jobDispatch->run_time_ms = $jobDispatch->completed_at && $jobDispatch->ran_at
					? $jobDispatch->ran_at->diffInMilliseconds($jobDispatch->completed_at)
					: null;
			}
		});
	}
}

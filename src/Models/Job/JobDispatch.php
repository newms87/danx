<?php

namespace Newms87\Danx\Models\Job;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Newms87\Danx\Helpers\DateHelper;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Traits\HasVirtualFields;

class JobDispatch extends Model
{
	use HasVirtualFields;

	const string
		STATUS_PENDING = 'Pending',
		STATUS_RUNNING = 'Running',
		STATUS_COMPLETE = 'Complete',
		STATUS_EXCEPTION = 'Exception',
		STATUS_FAILED = 'Failed',
		STATUS_TIMEOUT = 'Timeout';

	protected $fillable = [
		'user_id',
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

	protected $virtual = [
		'run_time' => [
			'ran_at',
			'completed_at',
		],
	];

	/**
	 * @return BelongsTo
	 */
	public function user(): BelongsTo
	{
		return $this->belongsTo(config('auth.providers.users.model'));
	}

	/**
	 * @param $ref
	 * @return JobDispatch|null
	 */
	public static function pendingJob($ref)
	{
		return self::where('ref', $ref)
			->where('status', self::STATUS_PENDING)
			->first();
	}

	/**
	 * @param $ref
	 * @return JobDispatch|null
	 */
	public static function runningJob($ref)
	{
		return self::where('ref', $ref)
			->where('status', self::STATUS_RUNNING)
			->first();
	}

	/**
	 * Checks if the job has past its defined time out period
	 *
	 * @return bool
	 */
	public function isTimedOut()
	{
		if ($this->timeout_at) {
			return $this->timeout_at->isPast();
		} else {
			return $this->created_at->addSeconds(90)->isPast();
		}
	}

	/**
	 * @return BelongsTo|JobBatch
	 */
	public function jobBatch()
	{
		return $this->belongsTo(JobBatch::class, 'job_batch_id');
	}

	/**
	 * @return BelongsTo|AuditRequest
	 */
	public function runningAuditRequest()
	{
		return $this->belongsTo(AuditRequest::class, 'running_audit_request_id');
	}

	/**
	 * @return BelongsTo|AuditRequest
	 */
	public function dispatchAuditRequest()
	{
		return $this->belongsTo(AuditRequest::class, 'dispatch_audit_request_id');
	}

	/**
	 * @param $ref
	 * @return void
	 */
	public static function incrementCount($ref)
	{
		JobDispatch::where('ref', $ref)
			->where('status', JobDispatch::STATUS_PENDING)
			->increment('count');
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return "Job $this->id ($this->ref) [Status: $this->status, Count: $this->count, Tag: $this->job_tag, Created: " . DateHelper::formatDateTime($this->created_at) . ']';
	}
}

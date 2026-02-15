<?php

namespace Newms87\Danx\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Newms87\Danx\Events\JobDispatchUpdatedEvent;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Models\Team\Team;
use Newms87\Danx\Traits\HasRelationCountersTrait;
use Newms87\Danx\Traits\HasVirtualFields;
use Newms87\Danx\Traits\SerializesDates;

class AuditRequest extends Model
{
	use HasRelationCountersTrait, HasVirtualFields, SerializesDates;

	public array $relationCounters = [
		ApiLog::class        => ['apiLogs' => 'api_log_count'],
		ErrorLogEntry::class => ['errorLogEntries' => 'error_log_count'],
	];

	public static function booted(): void
	{
		static::saving(function (AuditRequest $auditRequest) {
			if ($auditRequest->isDirty('logs')) {
				$auditRequest->log_line_count = $auditRequest->logs ? substr_count($auditRequest->logs, "\n") + 1 : 0;
			}
		});

		static::saved(function (AuditRequest $auditRequest) {
			if ($auditRequest->wasChanged(['api_log_count', 'error_log_count', 'log_line_count'])) {
				foreach($auditRequest->ranJobs as $jobDispatch) {
					JobDispatchUpdatedEvent::dispatch($jobDispatch, 'updated');
				}
			}
		});
	}

	protected $table = 'audit_request';

	protected $guarded = [];

	protected $casts = [
		'request'  => 'json',
		'response' => 'json',
	];

	protected $virtual = [
		'operation' => [
			'request',
		],
	];

	public function user()
	{
		return $this->belongsTo(config('auth.providers.users.model'));
	}

	public function team()
	{
		return $this->belongsTo(config('danx.models.team', Team::class));
	}

	/**
	 * @return HasMany|ErrorLogEntry[]
	 */
	public function errorLogEntries()
	{
		return $this->hasMany(ErrorLogEntry::class);
	}

	/**
	 * @return HasMany|ApiLog[]
	 */
	public function apiLogs()
	{
		return $this->hasMany(ApiLog::class);
	}

	/**
	 * @return HasMany|JobDispatch[]
	 */
	public function ranJobs()
	{
		return $this->hasMany(JobDispatch::class, 'running_audit_request_id');
	}

	/**
	 * @return HasMany|JobDispatch[]
	 */
	public function dispatchedJobs()
	{
		return $this->hasMany(JobDispatch::class, 'dispatch_audit_request_id');
	}

	/**
	 * @return HasMany|Audit[]|Audit
	 */
	public function audits()
	{
		return $this->hasMany(Audit::class);
	}

	public function scopeRequestMethod($query, $method)
	{
		return $query->whereIn('request->method', (array)$method);
	}

	/**
	 * Get the HTTP request method
	 *
	 * @return mixed|null
	 */
	public function requestMethod()
	{
		if ($this->request) {
			return $this->request['method'] ?? null;
		}

		return null;
	}

	/**
	 * Get the HTTP status code
	 *
	 * @return int|mixed|null
	 */
	public function statusCode()
	{
		if ($this->response) {
			return $this->response['status'] ?? 0;
		}

		return null;
	}
}

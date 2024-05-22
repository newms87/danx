<?php

namespace Newms87\DanxLaravel\Models\Audit;

use Newms87\DanxLaravel\Models\Job\JobDispatch;
use Newms87\DanxLaravel\Traits\HasVirtualFields;
use Newms87\DanxLaravel\Traits\SerializesDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRequest extends Model
{
	use SerializesDates, HasVirtualFields;

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
		return $query->where('request->method', $method);
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

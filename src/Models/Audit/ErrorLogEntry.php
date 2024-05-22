<?php

namespace Newms87\Danx\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLogEntry extends Model
{
	protected $table = 'error_log_entry';

	protected $guarded = [
		'id',
		'created_at',
		'updated_at',
	];

	protected $casts = [
		'data' => 'json',
	];

	/**
	 * @return BelongsTo|ErrorLog
	 */
	public function errorLog()
	{
		return $this->belongsTo(ErrorLog::class);
	}

	/**
	 * @return BelongsTo|AuditRequest
	 */
	public function auditRequest()
	{
		return $this->belongsTo(AuditRequest::class);
	}
}

<?php

namespace Newms87\Danx\Models\Job;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class JobDispatchable extends MorphPivot
{
	protected $table   = 'job_dispatchables';
	protected $guarded = [
		'id',
		'created_at',
		'updated_at',
	];

	public function jobDispatch(): BelongsTo|JobDispatch
	{
		return $this->belongsTo(JobDispatch::class);
	}
}

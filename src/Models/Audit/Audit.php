<?php

namespace Newms87\DanxLaravel\Models\Audit;

use Newms87\DanxLaravel\Traits\SerializesDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Audit as AuditModel;
use OwenIt\Auditing\Contracts\Audit as AuditContract;

class Audit extends Model implements AuditContract
{
	use AuditModel, SerializesDates;

	protected $table = 'audits';

	/**
	 * {@inheritdoc}
	 */
	protected $guarded = [];

	/**
	 * {@inheritdoc}
	 */
	protected $casts = [
		'old_values' => 'json',
		'new_values' => 'json',
	];

	/**
	 * @return MorphTo|mixed
	 */
	public function auditable()
	{
		return $this->morphTo();
	}

	/**
	 * @return BelongsTo|AuditRequest
	 */
	public function auditRequest()
	{
		return $this->belongsTo(AuditRequest::class);
	}

	/**
	 * @return BelongsTo
	 */
	public function user()
	{
		return $this->belongsTo(config('auth.providers.users.model'))
			->withTrashed();
	}
}

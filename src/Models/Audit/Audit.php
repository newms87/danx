<?php

namespace Newms87\Danx\Models\Audit;

use Newms87\Danx\Traits\SerializesDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
	 * The user who made the audited change. `withTrashed()` is a query-builder macro
	 * registered by `SoftDeletingScope`, not a static method or local scope every model
	 * carries -- calling it unconditionally throws `BadMethodCallException` for any consuming
	 * app whose configured user model (`auth.providers.users.model`) does not use
	 * `SoftDeletes`. `class_uses_recursive()` against the actual trait is the correct
	 * detection (same pattern `JobBatch::resolveIfComplete()` and
	 * `DanxServiceProvider::registerDanxRelationCounters()` already use) -- apply
	 * `withTrashed()` only when the related model can actually support it.
	 *
	 * @return BelongsTo
	 */
	public function user()
	{
		$userModel = config('auth.providers.users.model');
		$relation  = $this->belongsTo($userModel);

		if (in_array(SoftDeletes::class, class_uses_recursive($userModel), true)) {
			$relation->withTrashed();
		}

		return $relation;
	}
}

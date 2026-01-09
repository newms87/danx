<?php

namespace Newms87\Danx\Models\Team;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Newms87\Danx\Contracts\AuditableContract;
use Newms87\Danx\Traits\ActionModelTrait;
use Newms87\Danx\Traits\AuditableTrait;

class Team extends Model implements AuditableContract
{
    use ActionModelTrait, AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.providers.users.model'))->withTimestamps();
    }

    public function __toString()
    {
        return "<Team id='$this->id' name='$this->name'>";
    }
}

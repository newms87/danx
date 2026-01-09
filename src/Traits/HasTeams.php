<?php

namespace Newms87\Danx\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Newms87\Danx\Models\Team\Team;

trait HasTeams
{
    public ?object $currentTeam = null;

    public function teams(): BelongsToMany
    {
        $teamClass = config('danx.models.team', Team::class);

        return $this->belongsToMany($teamClass)->withTimestamps();
    }

    public function setCurrentTeam($uuid): static
    {
        $this->currentTeam = $uuid ? $this->teams()->firstWhere('uuid', $uuid) : null;

        return $this;
    }
}

<?php

namespace Newms87\Danx\Events;

use Newms87\Danx\Models\Audit\ApiLog;
use Newms87\Danx\Resources\Audit\ApiLogResource;

class ApiLogUpdatedEvent extends ModelSavedEvent
{
    public function __construct(protected ApiLog $apiLog, protected string $event)
    {
        parent::__construct(
            $apiLog,
            $event,
            ApiLogResource::class
        );
    }

    protected function getTeamId(): ?int
    {
        return $this->apiLog->auditRequest?->team_id;
    }

    protected function createdData(): array
    {
        return ApiLogResource::make($this->apiLog, [
            '*'           => false,
            'status_code' => true,
            'method'      => true,
            'url'         => true,
            'started_at'  => true,
            'created_at'  => true,
        ]);
    }

    protected function updatedData(): array
    {
        return ApiLogResource::make($this->apiLog, [
            '*'           => false,
            'status_code' => true,
            'finished_at' => true,
            'run_time_ms' => true,
        ]);
    }
}

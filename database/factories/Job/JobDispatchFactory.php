<?php

namespace Newms87\Danx\Database\Factories\Job;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Job\JobDispatch;

class JobDispatchFactory extends Factory
{
    protected $model = JobDispatch::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->randomElement(['ProcessDataJob', 'SyncRecordsJob', 'SendNotificationJob']),
            'ref'         => 'job:' . $this->faker->uuid(),
            'status'      => JobDispatch::STATUS_PENDING,
            'count'       => 1,
            'timeout_at'  => now()->addMinutes(5),
        ];
    }

    public function forAuditRequest(int $auditRequestId): static
    {
        return $this->state(fn(array $attributes) => [
            'running_audit_request_id' => $auditRequestId,
        ]);
    }

    public function dispatchedBy(int $auditRequestId): static
    {
        return $this->state(fn(array $attributes) => [
            'dispatch_audit_request_id' => $auditRequestId,
        ]);
    }

    public function withTeam(int $teamId): static
    {
        return $this->state(fn(array $attributes) => [
            'team_id' => $teamId,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => JobDispatch::STATUS_RUNNING,
            'ran_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'       => JobDispatch::STATUS_COMPLETE,
            'ran_at'       => now()->subSeconds(10),
            'completed_at' => now(),
            'run_time_ms'  => 10000,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'       => JobDispatch::STATUS_FAILED,
            'ran_at'       => now()->subSeconds(5),
            'completed_at' => now(),
            'run_time_ms'  => 5000,
        ]);
    }
}

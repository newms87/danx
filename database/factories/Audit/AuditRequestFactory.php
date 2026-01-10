<?php

namespace Newms87\Danx\Database\Factories\Audit;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Audit\AuditRequest;

class AuditRequestFactory extends Factory
{
    protected $model = AuditRequest::class;

    public function definition(): array
    {
        return [
            'url'         => $this->faker->url(),
            'request'     => json_encode(['method' => 'GET', 'path' => '/api/test']),
            'response'    => json_encode(['status' => 200]),
            'time'        => $this->faker->randomFloat(4, 0.01, 5.0),
            'session_id'  => $this->faker->uuid(),
            'logs'        => "DEBUG Test log entry\nINFO Another log entry",
            'environment' => 'testing',
        ];
    }

    public function withUser(int $userId): static
    {
        return $this->state(fn(array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    public function withTeam(int $teamId): static
    {
        return $this->state(fn(array $attributes) => [
            'team_id' => $teamId,
        ]);
    }
}

<?php

namespace Newms87\Danx\Database\Factories\Audit;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Audit\ApiLog;

class ApiLogFactory extends Factory
{
    protected $model = ApiLog::class;

    public function definition(): array
    {
        return [
            'service_name'     => $this->faker->randomElement(['OpenAI', 'Stripe', 'Twilio']),
            'api_class'        => 'App\\Api\\TestApi',
            'method'           => $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'url'              => '/v1/test',
            'full_url'         => $this->faker->url(),
            'status_code'      => $this->faker->randomElement([200, 201, 400, 404, 500]),
            'request_headers'  => ['Content-Type' => 'application/json'],
            'request'          => ['test' => 'data'],
            'response_headers' => ['Content-Type' => 'application/json'],
            'response'         => ['result' => 'success'],
            'run_time_ms'      => $this->faker->numberBetween(50, 5000),
            'started_at'       => now()->subSeconds(5),
            'finished_at'      => now(),
        ];
    }

    public function forAuditRequest(int $auditRequestId): static
    {
        return $this->state(fn(array $attributes) => [
            'audit_request_id' => $auditRequestId,
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn(array $attributes) => [
            'status_code' => $this->faker->randomElement([400, 401, 403, 404, 500, 502, 503]),
        ]);
    }
}

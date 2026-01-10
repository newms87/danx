<?php

namespace Newms87\Danx\Database\Factories\Audit;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Audit\ErrorLog;

class ErrorLogFactory extends Factory
{
    protected $model = ErrorLog::class;

    public function definition(): array
    {
        return [
            'level'              => ErrorLog::ERROR,
            'error_class'        => $this->faker->randomElement(['Exception', 'RuntimeException', 'InvalidArgumentException']),
            'code'               => 0,
            'message'            => $this->faker->sentence(),
            'file'               => '/app/Services/TestService.php',
            'line'               => $this->faker->numberBetween(1, 500),
            'stack_trace'        => [
                ['file' => '/app/test.php', 'line' => 10, 'function' => 'testMethod', 'class' => 'TestClass'],
                ['file' => '/app/caller.php', 'line' => 20, 'function' => 'caller', 'class' => 'CallerClass'],
            ],
            'hash'               => $this->faker->sha256(),
            'count'              => 1,
            'send_notifications' => true,
            'last_seen_at'       => now(),
        ];
    }

    public function withParent(int $parentId): static
    {
        return $this->state(fn(array $attributes) => [
            'parent_id' => $parentId,
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn(array $attributes) => [
            'level' => ErrorLog::WARNING,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn(array $attributes) => [
            'level' => ErrorLog::CRITICAL,
        ]);
    }
}

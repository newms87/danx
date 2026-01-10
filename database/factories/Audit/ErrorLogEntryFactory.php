<?php

namespace Newms87\Danx\Database\Factories\Audit;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Audit\ErrorLogEntry;

class ErrorLogEntryFactory extends Factory
{
    protected $model = ErrorLogEntry::class;

    public function definition(): array
    {
        return [
            'is_retryable' => false,
        ];
    }

    public function forErrorLog(int $errorLogId): static
    {
        return $this->state(fn(array $attributes) => [
            'error_log_id' => $errorLogId,
        ]);
    }

    public function forAuditRequest(int $auditRequestId): static
    {
        return $this->state(fn(array $attributes) => [
            'audit_request_id' => $auditRequestId,
        ]);
    }

    public function retryable(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_retryable' => true,
        ]);
    }
}

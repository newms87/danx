<?php

namespace Newms87\Danx\Repositories;

use Newms87\Danx\Exceptions\ValidationError;
use Newms87\Danx\Models\Audit\AuditRequest;

class AuditRequestRepository extends ActionRepository
{
    public static string $model = AuditRequest::class;

    public function applyAction(string $action, $model = null, ?array $data = null)
    {
        throw new ValidationError('Actions are not allowed on Audit Requests');
    }

    public function fieldOptions(?array $filter = []): array
    {
        $urls = $this->query()->distinct()->pluck('url')->toArray();

        return [
            'urls' => $urls,
        ];
    }
}

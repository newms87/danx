<?php

namespace Newms87\Danx\Repositories;

use Newms87\Danx\Exceptions\ValidationError;
use Newms87\Danx\Models\Audit\ApiLog;

class ApiLogRepository extends ActionRepository
{
    public static string $model = ApiLog::class;

    public function applyAction(string $action, $model = null, ?array $data = null)
    {
        throw new ValidationError('Actions are not allowed on API Logs');
    }
}

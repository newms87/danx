<?php

namespace Newms87\Danx\Repositories;

use Newms87\Danx\Exceptions\ValidationError;
use Newms87\Danx\Models\Job\JobDispatch;

class JobDispatchRepository extends ActionRepository
{
    public static string $model = JobDispatch::class;

    public function applyAction(string $action, $model = null, ?array $data = null)
    {
        throw new ValidationError('Actions are not allowed on Job Dispatches');
    }
}

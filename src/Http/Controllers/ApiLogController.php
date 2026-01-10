<?php

namespace Newms87\Danx\Http\Controllers;

use Newms87\Danx\Repositories\ApiLogRepository;
use Newms87\Danx\Resources\Audit\ApiLogResource;

class ApiLogController extends ActionController
{
    public static ?string $repo = ApiLogRepository::class;

    public static ?string $resource = ApiLogResource::class;
}

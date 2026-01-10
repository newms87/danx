<?php

namespace Newms87\Danx\Http\Controllers;

use Newms87\Danx\Repositories\JobDispatchRepository;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class JobDispatchController extends ActionController
{
    public static ?string $repo = JobDispatchRepository::class;

    public static ?string $resource = JobDispatchResource::class;
}

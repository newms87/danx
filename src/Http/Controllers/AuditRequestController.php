<?php

namespace Newms87\Danx\Http\Controllers;

use Newms87\Danx\Repositories\AuditRequestRepository;
use Newms87\Danx\Resources\Audit\AuditRequestResource;

class AuditRequestController extends ActionController
{
    public static ?string $repo = AuditRequestRepository::class;

    public static ?string $resource = AuditRequestResource::class;
}

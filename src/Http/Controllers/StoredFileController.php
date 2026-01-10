<?php

namespace Newms87\Danx\Http\Controllers;

use Newms87\Danx\Repositories\StoredFileRepository;
use Newms87\Danx\Resources\StoredFileResource;

class StoredFileController extends ActionController
{
    public static ?string $repo = StoredFileRepository::class;

    public static ?string $resource = StoredFileResource::class;
}

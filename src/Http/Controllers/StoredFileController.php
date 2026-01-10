<?php

namespace Newms87\Danx\Http\Controllers;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Repositories\StoredFileRepository;
use Newms87\Danx\Resources\StoredFileResource;
use Override;

class StoredFileController extends ActionController
{
    public static ?string $repo = StoredFileRepository::class;

    public static ?string $resource = StoredFileResource::class;

    #[Override]
    public function details($model): mixed
    {
        if ($model instanceof StoredFile) {
            $model->checkTranscodeTimeouts();
        }

        return parent::details($model);
    }
}

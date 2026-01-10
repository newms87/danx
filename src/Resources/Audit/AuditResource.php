<?php

namespace Newms87\Danx\Resources\Audit;

use Newms87\Danx\Models\Audit\Audit;
use Newms87\Danx\Resources\ActionResource;

class AuditResource extends ActionResource
{
    public static function data(Audit $audit, array $includeFields = []): array
    {
        return [
            'id'              => $audit->id,
            'event'           => $audit->event,
            'auditable_title' => $audit->auditable_type . ' (' . $audit->auditable_id . ')',
            'old_values'      => $audit->old_values,
            'new_values'      => $audit->new_values,
            'created_at'      => $audit->created_at,
        ];
    }
}

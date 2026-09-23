<?php

namespace Newms87\Danx\Contracts;

use DateTimeInterface;

/**
 * A model that decides for itself what its deletion means to a client record store, rather than
 * letting {@see \Newms87\Danx\Resources\ActionResource::typedData()} read `deleted_at` straight
 * off the column.
 *
 * A tombstone applies per store key (one per model + id), so the decision has to be made once,
 * on the model — never per resource. Two consuming-app examples this contract was built for: a
 * superseded reading (soft-deleted to retire it while keeping it on its own history chain) and a
 * record flagged as a mistake (soft-deleted but deliberately still shown, flagged) both need to
 * answer `null` here even though `deleted_at` itself is set; a genuinely deleted row still
 * answers its real `deleted_at`.
 */
interface HasRecordDeletedAt
{
    /**
     * What `__deleted_at` should read on the wire for this row. `null` means "not a tombstone
     * to a client record store" — the row itself may still be soft-deleted in the database.
     */
    public function recordDeletedAt(): ?DateTimeInterface;
}

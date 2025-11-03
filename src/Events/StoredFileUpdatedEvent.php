<?php

namespace Newms87\Danx\Events;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Resources\StoredFileResource;
use Newms87\Danx\Traits\BroadcastsWithSubscriptions;

class StoredFileUpdatedEvent extends ModelSavedEvent
{
    use BroadcastsWithSubscriptions;

    public function __construct(protected StoredFile $storedFile, protected string $event)
    {
        parent::__construct(
            $storedFile,
            $event,
            StoredFileResource::class,
            $storedFile->team_id
        );
    }

    public function broadcastOn(): array
    {
        // Use the new subscription-based broadcasting
        $userIds = $this->getSubscribedUsers(
            'StoredFile',
            $this->storedFile->team_id,
            $this->storedFile,
            StoredFile::class
        );

        return $this->getSubscribedChannels(
            'StoredFile',
            $this->storedFile->team_id,
            $userIds
        );
    }

    protected function createdData(): array
    {
        return StoredFileResource::make($this->storedFile, [
            '*'              => false,
            'id'             => true,
            'filename'       => true,
            'mime'           => true,
            'size'           => true,
            'url'            => true,
            'is_transcoding' => true,
            'created_at'     => true,
        ]);
    }

    protected function updatedData(): array
    {
        return StoredFileResource::make($this->storedFile, [
            '*'              => false,
            'is_transcoding' => true,
            'meta'           => true,
            'url'            => true,
            'size'           => true,
            'updated_at'     => true,
        ]);
    }
}

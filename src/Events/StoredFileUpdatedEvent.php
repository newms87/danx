<?php

namespace Newms87\Danx\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Resources\StoredFileResource;

class StoredFileUpdatedEvent extends ModelSavedEvent
{
	public function __construct(protected StoredFile $storedFile, protected string $event)
	{
		parent::__construct($storedFile, $event);
	}

	public function broadcastOn()
	{
		// Broadcast on a team channel if the file is associated with a team
		// Or broadcast on a user-specific channel
		if ($this->storedFile->team_id) {
			return new PrivateChannel('StoredFile.' . $this->storedFile->team_id);
		}

		// If file has a user_id, broadcast to that user
		if ($this->storedFile->user_id) {
			return new PrivateChannel('StoredFile.' . $this->storedFile->user_id);
		}

		// Default to broadcasting to all users who have access to the file
		// For simplicity, we'll use the user who created the file
		return new PrivateChannel('StoredFile.' . auth()->id());
	}

	public function data(): array
	{
		return StoredFileResource::make($this->storedFile, ['thumb' => true, 'transcodes' => true]);
	}
}

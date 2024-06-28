<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Models\Utilities\StoredFile;

abstract class FileTranscoderAbstract implements FileTranscoderContract
{
	/**
	 * The default starting progress is 90% (we assume we cannot make progress updates during the Transcoding, so show
	 * it almost completed)
	 */
	public function startingProgress(StoredFile $storedFile): float
	{
		return 90.0;
	}

	/**
	 * The default time estimate is 5 seconds + 5 seconds / MB
	 */
	public function timeEstimate(StoredFile $storedFile): int
	{
		$MB = 1024 * 1024;

		// 1 second per MB
		return 5000 + ($storedFile->size / $MB * 5000);
	}
}

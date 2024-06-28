<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Models\Utilities\StoredFile;

interface FileTranscoderContract
{
	/**
	 * Provides a starting progress value for this transcode.
	 *
	 * Most transcodes will not have async capabilities, so, for example, starting progress of 90% can be useful in
	 * conjunction w/ time estimate to show a progress bar that fills to 90% over the estimated time frame for example.
	 */
	public function startingProgress(StoredFile $storedFile): float;

	/**
	 * Provides an estimate in ms of how long this transcode will take
	 */
	public function timeEstimate(StoredFile $storedFile): int;

	/**
	 * Run the transcode and return an array of transcode files
	 */
	public function run(StoredFile $storedFile, array $options = []): array;
}

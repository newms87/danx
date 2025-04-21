<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;
use Throwable;

class TranscodeCleanCommand extends Command
{
	protected $signature   = 'transcode:clean';
	protected $description = 'Clean up any timed out transcodes';

	/**
	 * @throws Throwable
	 */
	public function handle()
	{
		$transcodingFiles = StoredFile::where('is_transcoding', 1)->get();

		$this->info("\nChecking for transcoding files that have timed out in " . count($transcodingFiles) . " files\n");

		foreach($transcodingFiles as $transcodingFile) {
			$this->checkForTimeout($transcodingFile);
		}
	}

	public function checkForTimeout(StoredFile $storedFile): void
	{
		foreach($storedFile->meta['transcodes'] ?? [] as $transcodeName => $transcodeItem) {
			$status    = $transcodeItem['status'] ?? null;
			$timeoutAt = $transcodeItem['timeout_at'] ?? null;

			$timeoutInSeconds = ($timeoutAt ? round(carbon()->diffInSeconds($timeoutAt)) : 'N/A');
			$this->output && $this->comment("Check stored file $storedFile->id > $transcodeName: status $status will time out in $timeoutInSeconds seconds @ $timeoutAt");

			if ($status !== TranscodeFileService::STATUS_COMPLETE && carbon($timeoutAt)->isPast()) {
				$this->output && $this->warn("\tFile transcode has timed out");
				
				$storedFile->setMeta('transcodes', [
					$transcodeName => ['status' => TranscodeFileService::STATUS_TIMEOUT],
				])->save();
			}
		}
	}
}

<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class TranscodeDataUrlToStoredFileJob extends Job
{
	/**
	 * 120-second worker timeout. The download inside TranscodeFileService::moveDataUrlToStoredFile
	 * uses 50s + 1 retry (worst case ~100s) so this floor is wide enough to allow a retry
	 * to complete inside the worker without SIGTERM.
	 */
	public int $timeout = 120;

	private StoredFile $storedFile;
	private string     $transcodeName;
	private array      $transcodedFile;

	public function __construct(StoredFile $storedFile, string $transcodeName, array $transcodedFile)
	{
		$this->storedFile     = $storedFile;
		$this->transcodeName  = $transcodeName;
		$this->transcodedFile = $transcodedFile;
		parent::__construct();
	}

	public function ref(): string
	{
		return 'transcode-data-url-to-stored-file:' . $this->transcodeName . ':' . $this->storedFile->id . ':' . md5(json_encode($this->transcodedFile));
	}

	/**
	 * Reached exclusively via the OCR callback chain (OcrCallbackController ->
	 * ProcessOcrCallbackJob -> TranscodePrerequisiteService::persistOcrResult ->
	 * TranscodeFileService::moveDataUrlToStoredFile) -- a machine-to-machine
	 * webhook authenticated by a callback token, never a Laravel user session,
	 * so no user/team context exists to inherit. Safe: moveDataUrlToStoredFile()
	 * -> storeTranscodedFile() sets team_id explicitly from the already-resolved
	 * $storedFile relation, never from Auth/team() globals. Mirrors
	 * ProcessOcrCallbackJob's identical override for the same reason.
	 */
	protected function requiresAuth(): bool
	{
		return false;
	}

	public function run()
	{
		app(TranscodeFileService::class)->moveDataUrlToStoredFile($this->storedFile, $this->transcodeName, $this->transcodedFile);
	}
}

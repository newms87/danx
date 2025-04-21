<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class TranscodeDataUrlToStoredFileJob extends Job
{
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

	public function run()
	{
		app(TranscodeFileService::class)->moveDataUrlToStoredFile($this->storedFile, $this->transcodeName, $this->transcodedFile);
	}
}

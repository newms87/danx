<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class TranscodeStoredFileJob extends Job
{
	public function __construct(protected StoredFile $storedFile, protected string $transcodeName)
	{
		parent::__construct();
	}

	public function ref(): string
	{
		return 'transcode-stored-file:' . $this->transcodeName . ':' . $this->storedFile->id;
	}

	public function run()
	{
		app(TranscodeFileService::class)->transcode($this->transcodeName, $this->storedFile);
	}
}

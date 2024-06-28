<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Models\Utilities\StoredFile;

interface FileTranscoderContract
{
	public function run(StoredFile $storedFile, array $options = []);
}

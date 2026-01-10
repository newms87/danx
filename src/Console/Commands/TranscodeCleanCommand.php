<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Utilities\StoredFile;
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

        foreach ($transcodingFiles as $transcodingFile) {
            $this->checkForTimeout($transcodingFile);
        }
    }

    public function checkForTimeout(StoredFile $storedFile): void
    {
        $wasTranscoding = $storedFile->is_transcoding;
        $storedFile->checkTranscodeTimeouts();

        if ($wasTranscoding && !$storedFile->is_transcoding) {
            $this->warn("File $storedFile->id has timed out");
        }
    }
}

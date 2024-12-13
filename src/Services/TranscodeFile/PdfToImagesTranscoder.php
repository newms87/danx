<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Api\ConvertApi\ConvertApi;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Models\Utilities\StoredFile;

class PdfToImagesTranscoder extends FileTranscoderAbstract implements FileTranscoderContract
{
	public function timeEstimate(StoredFile $storedFile): int
	{
		$MB = 1024 * 1024;

		// 5s + 7.5s per MB for PDF to Images via Convert API
		return 5000 + (int)round($storedFile->size / $MB * 7500);
	}

	public function run(StoredFile $storedFile, array $options = []): array
	{
		$result = app(ConvertApi::class)->pdfToImage($storedFile->url);

		if (!isset($result['Files'])) {
			throw new ApiException("Convert API did not return any files for PDF to Images transcode\n\n" . json_encode($result));
		}

		$transcodedFiles = [];

		foreach($result['Files'] as $page => $image) {
			$transcodedFiles[] = [
				'filename' => "Page " . ($page + 1) . " -- $image[FileName]",
				'data'     => base64_decode($image['FileData']),
			];
		}

		return $transcodedFiles;
	}
}

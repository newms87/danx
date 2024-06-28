<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Api\ConvertApi\ConvertApi;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Models\Utilities\StoredFile;

class PdfToImagesTranscoder implements FileTranscoderContract
{
	public function run(StoredFile $storedFile, array $options = []): array
	{
		$result = app(ConvertApi::class)->pdfToImage($storedFile->url);

		if (!isset($result['Files'])) {
			throw new ApiException("Convert API did not return any files for PDF to Images transcode\n\n" . json_encode($result));
		}

		$transcodedFiles = [];

		foreach($result['Files'] as $image) {
			$transcodedFiles[] = [
				'filename' => $image['FileName'],
				'data'     => base64_decode($image['FileData']),
			];
		}

		return $transcodedFiles;
	}
}

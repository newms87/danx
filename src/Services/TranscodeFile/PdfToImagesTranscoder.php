<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Newms87\Danx\Api\ConvertApi\ConvertApi;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Models\Utilities\StoredFile;

class PdfToImagesTranscoder extends FileTranscoderAbstract implements FileTranscoderContract
{
	public function usesDataUrls(): bool
	{
		return true;
	}

	public function getTimeout(StoredFile $storedFile): int
	{
		return (int)($this->timeEstimate($storedFile) / 1000 * 3);
	}

	public function timeEstimate(StoredFile $storedFile): int
	{
		$MB = 1024 * 1024;

		// 5s + 7.5s per MB for PDF to Images via Convert API
		return 5000 + (int)round($storedFile->size / $MB * 7500);
	}

	/**
	 * @param array $options Supported keys:
	 *   - page_range (string|null): ConvertAPI PageRange ("3,7,12-15"). When set, only the
	 *     named pages are rendered. The returned `page_number` reflects the PDF page index
	 *     parsed from the ConvertAPI filename (Page-X) so partial recovery can stitch
	 *     results into the original page numbering.
	 *   - page_numbers (int[]|null): explicit page numbers to render. Internally collapsed
	 *     into a PageRange string ("3,7,12-15") for ConvertAPI.
	 */
	public function run(StoredFile $storedFile, array $options = []): array
	{
		$pageRange = $options['page_range'] ?? null;
		if (!$pageRange && !empty($options['page_numbers'])) {
			$pageRange = $this->compressPageNumbers($options['page_numbers']);
		}

		$result = app(ConvertApi::class)->pdfToImage($storedFile->url, $pageRange);

		if (!isset($result['Files'])) {
			throw new ApiException("Convert API did not return any files for PDF to Images transcode\n\n" . json_encode($result));
		}

		$transcodedFiles      = [];
		$requestedPageNumbers = $pageRange ? $this->expandPageRange($pageRange) : null;

		foreach($result['Files'] as $position => $image) {
			$url = $image['Url'] ?? $image['FileUrl'] ?? null;

			if (!$url) {
				throw new ApiException("Convert API did not return a URL for PDF to Images transcode\n\n" . json_encode($image));
			}

			// When a PageRange was sent, ConvertAPI returns the files in PageRange order — the
			// loop index is the position in the response, not the original PDF page. Map back
			// to the requested page number so transcode rows reference the true PDF page.
			$pageNumber = $requestedPageNumbers
				? ($requestedPageNumbers[$position] ?? ($position + 1))
				: ($position + 1);

			$transcodedFiles[] = [
				'filename'    => "Page $pageNumber -- $image[FileName]",
				'url'         => $url,
				'page_number' => $pageNumber,
			];
		}

		return $transcodedFiles;
	}

	private function compressPageNumbers(array $pageNumbers): string
	{
		$pages = array_values(array_unique(array_map('intval', $pageNumbers)));
		sort($pages);

		if (empty($pages)) {
			return '';
		}

		$runs    = [];
		$start   = $pages[0];
		$prev    = $start;

		foreach (array_slice($pages, 1) as $page) {
			if ($page === $prev + 1) {
				$prev = $page;
				continue;
			}

			$runs[] = $start === $prev ? (string)$start : "$start-$prev";
			$start  = $page;
			$prev   = $page;
		}

		$runs[] = $start === $prev ? (string)$start : "$start-$prev";

		return implode(',', $runs);
	}

	private function expandPageRange(string $pageRange): array
	{
		$pages = [];
		foreach (explode(',', $pageRange) as $segment) {
			$segment = trim($segment);
			if ($segment === '') {
				continue;
			}

			if (str_contains($segment, '-')) {
				[$from, $to] = array_map('intval', explode('-', $segment, 2));
				for ($p = $from; $p <= $to; $p++) {
					$pages[] = $p;
				}
			} else {
				$pages[] = (int)$segment;
			}
		}

		return $pages;
	}
}

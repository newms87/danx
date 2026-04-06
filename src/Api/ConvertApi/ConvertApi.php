<?php

declare(strict_types = 1);

namespace Newms87\Danx\Api\ConvertApi;

use Imagick;
use ImagickPixel;
use Newms87\Danx\Api\BearerTokenApi;
use Newms87\Danx\Exceptions\ValidationError;

/**
 * ConvertAPI integration for converting web pages, PDFs, and images.
 *
 * Provides web-to-PDF, web-to-image (with optional Imagick trim), PDF-to-text,
 * and PDF-to-image conversions via the ConvertAPI service.
 */
class ConvertApi extends BearerTokenApi
{
	protected array $rateLimits = [
		[
			'limit'          => 1000,
			'interval'       => 3600,
			'waitPerAttempt' => 1,
		],
	];

	public static string $serviceName = 'convert-api';

	public function __construct()
	{
		$this->token      = config('convertapi.api_token');
		$this->baseApiUrl = config('convertapi.url');
	}

	/**
	 * Convert a webpage to a PDF file
	 */
	public function webToPdf(string $url, array $params = []): ?array
	{
		$params = $params + [
				'Url'             => $url,
				'WaitElement'     => '.all-content-loaded',
				'UserCss'         => '@page { margin-left: 20mm!important } * {transition: none !important; animation-duration: 0s !important}',
				'HideElements'    => '#loading-app',
				'CssMediaType'    => 'print',
				'LoadLazyContent' => 'true',
				'ViewportWidth'   => '816',
				'ViewportHeight'  => '1056',
				'RespectViewport' => 'false',
				'MarginTop'       => '0',
				'MarginRight'     => '0',
				'MarginBottom'    => '0',
				'MarginLeft'      => '0',
				'PageSize'        => 'a4',
				'Timeout'         => 120,
				'CompressPDF'     => 'true',
			];

		return $this->get('web/to/pdf', $params)->json();
	}

	/**
	 * Convert a PDF to text
	 */
	public function pdfToText(string $url): ?array
	{
		$params = [
			'Parameters' => [
				[
					'Name'      => 'File',
					'FileValue' => [
						'Url' => $url,
					],
				],
				[
					'Name'  => 'RemoveHeadersFooters',
					'Value' => true,
				],
				[
					'Name'  => 'RemoveFootnotes',
					'Value' => true,
				],
			],
		];

		return $this->post('pdf/to/txt', $params)->json();
	}

	/**
	 * Capture a screenshot of a webpage as a JPG image.
	 *
	 * Waits for the .all-content-loaded CSS class before capturing, matching
	 * the webToPdf() convention. Returns the ConvertAPI response with image file URLs.
	 */
	public function webToImage(string $url, array $params = []): ?array
	{
		$params = $params + [
				'Url'             => $url,
				'WaitElement'     => '.all-content-loaded',
				'HideElements'    => '#loading-app',
				'LoadLazyContent' => 'true',
				'ViewportWidth'   => '1024',
				'ViewportHeight'  => '16000',
				'ImageWidth'      => '1024',
				'ImageHeight'     => '16000',
				'Timeout'         => 120,
				'StoreFile'       => 'true',
			];

		// Guzzle timeout must exceed ConvertAPI's server-side Timeout to avoid premature disconnect
		$this->setNextTimeout($params['Timeout'] + 30);

		return $this->get('web/to/jpg', $params)->json();
	}

	/**
	 * Capture a webpage screenshot, trim whitespace, and return raw JPEG bytes.
	 *
	 * ConvertAPI renders at a tall viewport height (default 16000px), producing large
	 * blank areas below the actual content. This method captures the screenshot, downloads
	 * the image, trims whitespace with Imagick, and optionally adds padding around the
	 * trimmed content.
	 *
	 * @param string   $url     The publicly accessible URL to screenshot
	 * @param array    $params  Optional ConvertAPI parameter overrides
	 * @param int|null $padding Pixels of white padding to add around trimmed content (null = no padding)
	 * @return string Raw JPEG image bytes of the trimmed screenshot
	 */
	public function webToTrimmedImage(string $url, array $params = [], ?int $padding = null): string
	{
		$response  = $this->webToImage($url, $params);
		$imageUrls = $this->extractImageUrls($response);

		// Download the first image from ConvertAPI's temporary storage
		$imageData = file_get_contents($imageUrls[0]);
		if (!$imageData) {
			throw new ValidationError('Failed to download screenshot from ConvertAPI');
		}

		return $this->trimImageWhitespace($imageData, $padding);
	}

	/**
	 * Trim whitespace from image data and optionally add padding.
	 *
	 * Uses Imagick to detect the bounding box of non-white content, crops to that
	 * region, and adds uniform white padding if specified.
	 *
	 * @param string   $imageData Raw image bytes
	 * @param int|null $padding   Pixels of white padding to add around content (null = no padding)
	 * @return string Raw JPEG image bytes of the trimmed (and optionally padded) image
	 */
	public function trimImageWhitespace(string $imageData, ?int $padding = null): string
	{
		$imagick = new Imagick();
		$imagick->setResourceLimit(Imagick::RESOURCETYPE_WIDTH, 25000);
		$imagick->setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, 25000);
		$imagick->readImageBlob($imageData);

		// Find the bounding box of non-white content
		$imagick->trimImage(0);
		$page = $imagick->getImagePage();

		if ($page['height'] > 0 && $page['height'] < $imagick->getImageHeight()) {
			$imagick->cropImage(
				$page['width'],
				$page['height'],
				$page['x'],
				$page['y'],
			);
			$imagick->setImagePage($page['width'], $page['height'], 0, 0);
		}

		// Add uniform white padding around the trimmed content
		if ($padding && $padding > 0) {
			$imagick->borderImage(new ImagickPixel('#FFFFFF'), $padding, $padding);
			$imagick->setImagePage($imagick->getImageWidth(), $imagick->getImageHeight(), 0, 0);
		}

		$imagick->setImageFormat('jpeg');
		$result = $imagick->getImageBlob();
		$imagick->destroy();

		return $result;
	}

	/**
	 * Extract image URLs from a ConvertAPI response.
	 *
	 * @return string[]
	 */
	private function extractImageUrls(?array $response): array
	{
		if (!$response || !isset($response['Files'])) {
			throw new ValidationError('ConvertAPI returned no files');
		}

		return collect($response['Files'])
			->pluck('Url')
			->filter()
			->values()
			->toArray();
	}

	/**
	 * Convert a PDF to a list of images (1 per page of the PDF)
	 */
	public function pdfToImage(string $url): ?array
	{
		$params = [
			'Parameters' => [
				[
					'Name'      => 'File',
					'FileValue' => [
						'Url' => $url,
					],
				],
				[
					'Name'  => 'StoreFile',
					'Value' => true,
				],
			],
		];

		return $this->post('pdf/to/jpg', $params)->json();
	}
}

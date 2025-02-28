<?php

namespace Newms87\Danx\Api\ConvertApi;

use Newms87\Danx\Api\BearerTokenApi;

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
	public function webToPdf($url, $params = [])
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
	public function pdfToText($url): ?array
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
	 * Convert a PDF to a list of images (1 per page of the PDF)
	 */
	public function pdfToImage($url): ?array
	{
		$params = [
			'Parameters' => [
				[
					'Name'      => 'File',
					'FileValue' => [
						'Url' => $url,
					],
				],
			],
		];

		return $this->post('pdf/to/jpg', $params)->json();
	}
}

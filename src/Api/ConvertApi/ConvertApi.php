<?php

namespace Newms87\Danx\Api\ConvertApi;

use Newms87\Danx\Api\BasicAuthApi;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Exceptions\ApiRequestException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ConvertApi extends BasicAuthApi
{
	const int THROTTLE_ATTEMPTS      = 1000;
	const int THROTTLE_DECAY_SECONDS = 3600;

	public static string $serviceName = 'convert-api';

	public function __construct()
	{
		$this->clientId     = config('convertapi.api_key');
		$this->clientSecret = config('convertapi.secret_key');
	}

	public function getBaseApiUrl(): string
	{
		return config('convertapi.url');
	}

	/**
	 * @param       $url
	 * @param array $params
	 * @return mixed|null
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
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
	 * @param $url
	 * @return array|null
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws ApiException
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
	 * @param $url
	 * @return array|null
	 * @throws ApiException
	 * @throws ApiRequestException
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
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

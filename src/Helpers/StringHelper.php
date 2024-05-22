<?php

namespace Newms87\DanxLaravel\Helpers;

use Exception;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Throwable;

class StringHelper
{
	public static function currency($value)
	{
		return '$' . number_format($value, 2);
	}

	/**
	 * @param $url
	 * @param $segments
	 * @return string
	 */
	public static function url($url, $segments = [])
	{
		if (!$segments) {
			$segments = [
				PHP_URL_SCHEME,
				PHP_URL_HOST,
				PHP_URL_PORT,
				PHP_URL_PATH,
				PHP_URL_QUERY,
				PHP_URL_FRAGMENT,
			];
		}

		if (!str_contains($url, '//')) {
			$url = '//' . $url;
		}

		$parts = parse_url($url);

		$formatted = '';

		foreach($segments as $segment) {
			$formatted .= match ($segment) {
				PHP_URL_SCHEME => !empty($parts['scheme']) ? $parts['scheme'] . '://' : '//',
				PHP_URL_HOST => $parts['host'] ?? '',
				PHP_URL_PORT => !empty($parts['port']) ? ':' . $parts['port'] : '',
				PHP_URL_PATH => $parts['path'] ?? '',
				PHP_URL_QUERY => !empty($parts['query']) ? '?' . $parts['query'] : '',
				PHP_URL_FRAGMENT => !empty($parts['fragment']) ? '#' . $parts['fragment'] : '',
			};
		}

		return $formatted;
	}

	/** Parse name into two parts
	 *
	 * @return array
	 */
	public static function parseName($name)
	{
		$parts = explode(' ', $name);
		if (count($parts) === 1) {
			$lastName  = null;
			$firstName = implode('', $parts);
		} else {
			$lastName  = array_pop($parts);
			$firstName = implode(' ', $parts);
		}

		return [$firstName, $lastName];
	}

	/**
	 * Parses a string into an array of dimensions w/ width, height, and unit
	 * or width_ratio and height_ratio
	 *
	 * @param $dimensionsStr
	 * @return array
	 */
	public static function parseDimensions($dimensionsStr)
	{
		$dimensions = [];

		preg_match('/^([\d.]+)\s*x\s*([\d.]+)\s*(\w*)$/', $dimensionsStr, $matches);
		if (count($matches) === 4) {
			$dimensions['width']  = $matches[1];
			$dimensions['height'] = $matches[2];
			$dimensions['unit']   = $matches[3];
		} else {
			preg_match('/^([\d.]+)\s*:\s*([\d.]+)\s*$/', $dimensionsStr, $matches);
			if (count($matches) === 3) {
				$dimensions['width_ratio']  = $matches[1];
				$dimensions['height_ratio'] = $matches[2];
			}
		}

		return $dimensions;
	}

	/**
	 * @param $json
	 * @return mixed
	 */
	public static function parseJson($json)
	{
		$json = preg_replace('/("(.*?)"|(\w+))(\s*:\s*)\+?(0+(?=\d))?(".*?"|.)/s', '"$2$3"$4$6', $json);

		return json_decode($json, true);
	}

	/**
	 * @param $data
	 * @return false|string
	 */
	public static function safeJsonEncode($data)
	{
		if (is_string($data)) {
			return json_encode(mb_convert_encoding($data, 'UTF-8', 'UTF-8'));
		}

		if (is_object($data)) {
			$data = (array)$data;
		}

		if (is_array($data)) {
			return json_encode(static::convertArrayToUtf8($data));
		}

		return json_encode($data);
	}

	/**
	 * @param     $string
	 * @param int $maxEntrySize
	 * @return array|mixed|null
	 */
	public static function safeJsonDecode($string, int $maxEntrySize = 10000)
	{
		if ($string) {
			$string  = static::safeConvertToUTF8($string);
			$jsonObj = json_decode($string, true);

			if (!$jsonObj) {
				$jsonObj = ['content' => $string];

				try {
					json_encode($jsonObj);
				} catch(Exception $exception) {
					$jsonObj = [
						'contentError' => 'JSON encoding failed',
						'message'      => static::safeConvertToUTF8($exception->getMessage()),
					];
				}
			}

			if (is_array($jsonObj)) {
				self::limitArrayEntryLength($jsonObj, $maxEntrySize);
			}

			return $jsonObj;
		}

		// If no string contents are given, just return null
		return null;
	}

	/**
	 * @param $result
	 * @return mixed|null
	 */
	public static function getJsonFromText($message)
	{
		// Parse the JSON piece of the message from a blob of text
		$json = preg_replace('/^[^{]*/', '', $message);
		$json = preg_replace('/[^}]*$/', '', $json);

		if ($json) {
			// Escape ctrl characters
			$json = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
				return json_decode('"' . $match[0] . '"');
			}, $json);
			$json = str_replace("\",\n", '",', $json);
			$json = preg_replace("/{\s+/", '{', $json);
			$json = preg_replace("/\s+}/", '}', $json);

			// Decode the JSON
			$decoded = json_decode($json, true);

			// If multiple newline separated json objects are detected, only use the last one
			if (!$decoded) {
				$json    = preg_replace("/.*\\}\s+\\{/", '{', $json);
				$decoded = json_decode($json, true);

				// Try replacing line breaks as a last resort
				if (!$decoded) {
					// Replace literal line breaks with line break char
					$json    = str_replace("\n", '\n', $json);
					$decoded = json_decode($json, true);
				}
			}

			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			}

			Log::error("JSON from text parse error: " . json_last_error_msg() . "\n" . $json);
		}

		return null;
	}

	/**
	 * Modified (in place) an array so that each entry is at most the maxExtrySize in length
	 *
	 * @param array $array
	 * @param int   $maxEntrySize
	 */
	public static function limitArrayEntryLength(array &$array, int $maxEntrySize)
	{
		foreach($array as &$value) {
			if (is_array($value)) {
				self::limitArrayEntryLength($value, $maxEntrySize);
			} elseif (is_string($value)) {
				if (strlen($value) > $maxEntrySize) {
					$value = strlen($value);
				}
			}
		}
		unset($value);
	}

	/**
	 * @param int    $limit           The maximum length of the string, first characters of the prefix will be removed
	 *                                (starting from the end of the string) to make room for the suffix
	 * @param string $prefix
	 * @param string $suffix
	 * @param int    $minPrefixLength If set, the prefix will be not be shortened (starting from the end) any further
	 *                                than this length
	 * @return string
	 * @throws Exception
	 */
	public static function limitText(
		$limit,
		$prefix,
		$suffix = '',
		$minPrefixLength = 0
	)
	{
		if ($minPrefixLength > $limit) {
			throw new Exception('minPrefixLength cannot be greater than limit');
		}

		$requiredPrefix = '';

		if ($minPrefixLength > 0) {
			$limit          -= $minPrefixLength;
			$requiredPrefix = substr($prefix, 0, $minPrefixLength);
			$prefix         = substr($prefix, $minPrefixLength);
		}

		if (strlen($suffix) > $limit) {
			return $requiredPrefix . substr($suffix, 0, $limit);
		}

		$limit -= strlen($suffix);

		return $requiredPrefix . substr($prefix, 0, $limit) . $suffix;
	}

	/**
	 * Safely encodes a URL parameter as base64 w/o special URL chars
	 *
	 * @param $param
	 * @return array|string|string[]
	 */
	public static function urlBase64Encode($param)
	{
		$str = base64_encode($param);

		return str_replace(['+', '/'], ['-', '_'], $str);
	}

	/**
	 * Checks if a string matches the signature of a JWT token
	 *
	 * @param $string
	 * @return false|int
	 */
	public static function isPossibleJWT($string)
	{
		return (bool)preg_match('/[a-z0-9+\\-=\\/]+\\.[a-z0-9+\\-=\\/]+\\.[a-z0-9+\\-_=\\/]+/i', $string);
	}

	/**
	 * Format a string into all UTF-8 characters to make it safe for database / file log readers.
	 * Also limit the text to a max length. If the text is not safe / too long, convert it into a printable number of
	 * bytes.
	 *
	 * @param $string
	 * @param $maxLength
	 * @return array|false|string|string[]|null
	 */
	public static function logSafeString($string, $maxLength = 10000)
	{
		if (!is_string($string)) {
			$string = static::safeJsonEncode($string);
		}

		// Make sure the string is at most $maxLength characters
		if (mb_detect_encoding($string) === false || strlen($string) > $maxLength) {
			// Split the string, so it is $maxLength characters with the first 90% from the start and 10% from the end of the string.
			// Add an ellipsis in the middle indicating a break
			$string = substr($string, 0, $maxLength * 0.9) . "\n\n...\n\n" . substr($string, -($maxLength * 0.1));

			// Append the string length
			$string .= " \n(" . strlen($string) . ' bytes)';
		}

		return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', mb_convert_encoding($string, 'UTF-8', 'UTF-8'));
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public static function safeConvertToUTF8(string $string): string
	{
		try {
			// Try to filter out binary or strings that are non-human readable characters if they are longer than 500 characters
			if (strlen($string) > 500 & preg_match_all('/[^\x20-\x7E\t\r\n\x00]/', $string) > 500) {
				return "Non-UTF-8 string of length " . strlen($string) . " bytes.";
			}

			// Detect the current encoding of the string.
			$currentEncoding = mb_detect_encoding($string, mb_detect_order(), true);

			if ($currentEncoding === false) {
				return "Unknown encoding for string of length " . strlen($string) . " bytes.";
			}

			// If the string is already in UTF-8, no conversion is necessary.
			if (strcasecmp($currentEncoding, 'UTF-8') === 0) {
				return $string;
			}

			// Convert the string to UTF-8 from the detected encoding.
			$convertedString = mb_convert_encoding($string, 'UTF-8', $currentEncoding);

			// Check if the conversion was successful.
			if ($convertedString === false) {
				return "Failed to convert string from $currentEncoding to UTF-8. String length " . strlen($string) . " bytes.";
			}

			return $convertedString;
		} catch(Throwable $exception) {
			return "Failed to convert string to UTF-8: " . $exception->getMessage() . ".\n\nString length " . strlen($string) . " bytes.";
		}
	}

	/**
	 * @param $encryptKey
	 * @param $string
	 * @param $cipher
	 * @return string
	 */
	public static function encryptString($encryptKey, $string, $cipher = 'aes-256-cbc')
	{
		$encrypter = new Encrypter($encryptKey, $cipher);

		return $encrypter->encryptString($string);
	}

	/**
	 * Converts all non-UTF-8 strings in a nested array to UTF-8.
	 * @param array $nestedArray
	 * @return array
	 */
	public static function convertArrayToUtf8(array &$nestedArray): array
	{
		foreach($nestedArray as &$item) {
			if (is_array($item)) {
				static::convertArrayToUtf8($item);
			} elseif (is_string($item)) {
				if (!mb_check_encoding($item, 'UTF-8')) {
					// Set the substitution character to "none" to discard invalid characters.
					mb_substitute_character('none');
					// Convert the non-UTF-8 string to UTF-8 using mb_convert_encoding().
					$item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
				}
			}
		}
		unset($item);

		return $nestedArray;
	}
}

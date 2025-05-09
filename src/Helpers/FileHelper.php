<?php

namespace Newms87\Danx\Helpers;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;
use Newms87\Danx\Library\CsvExport;
use Newms87\Danx\Models\Utilities\StoredFile as FileModel;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Yaml\Dumper as YamlDumper;
use Symfony\Component\Yaml\Parser as YamlParser;
use Throwable;
use ZipArchive;

class FileHelper
{
	/**
	 * @param $name
	 * @return array|string|string[]|null
	 */
	public static function safeFilename($name)
	{
		$name = preg_replace('/[\\s%]/', '-', urldecode($name));

		if (strlen($name) > 103) {
			$extension = pathinfo($name, PATHINFO_EXTENSION);
			$name      = rtrim(substr($name, 0, 100), '. -%_()*&^$#@!') . '.' . $extension;
		}

		return $name;
	}

	/**
	 * Returns a human-readable string representing the number of bytes in the given number
	 *
	 * @param $byteSize
	 * @return string
	 */
	public static function getHumanSize($byteSize)
	{
		$powers = [
			['pow' => 0, 'unit' => 'B'],
			['pow' => 10, 'unit' => 'KB'],
			['pow' => 20, 'unit' => 'MB'],
			['pow' => 30, 'unit' => 'GB'],
			['pow' => 40, 'unit' => 'TB'],
			['pow' => 50, 'unit' => 'PB'],
		];

		// Should always be an integer
		$byteSize = round($byteSize);

		foreach($powers as $power) {
			// Check the next unit up minimum value to get our current unit max
			$max = pow(2, $power['pow'] + 10);

			if ($max > $byteSize) {
				break;
			}
		}

		// Using PHP's scoping to our advantage ($power is set to most recent iteration in for loop)
		return round($byteSize / pow(2, $power['pow'])) . ' ' . $power['unit'];
	}

	/**
	 * Convert an array of records into a CSV string
	 * @param $data
	 * @param $delimiter
	 * @param $enclosure
	 * @param $escapeChar
	 * @return false|string
	 */
	public static function arrayToCsv($data, $delimiter = ',', $enclosure = '"', $escapeChar = "\\")
	{
		// Open a memory "file" for read/write...
		$fp = fopen('php://memory', 'r+');

		// Check if data is not empty
		if (empty($data)) {
			return false;
		}

		// Use the array keys as column headers
		fputcsv($fp, array_keys(reset($data)), $delimiter, $enclosure, $escapeChar);

		// Loop over the data, outputting each row
		foreach($data as $row) {
			fputcsv($fp, $row, $delimiter, $enclosure, $escapeChar);
		}

		// Rewind the "file" so we can read what we just wrote...
		rewind($fp);

		// Read all the data back and capture the output
		$csv = stream_get_contents($fp);

		// Close the "file"...
		fclose($fp);

		// Return the CSV data
		return $csv;
	}

	/**
	 * Convert an associative array of records into a CSV string
	 *
	 * @param $records
	 * @return bool|string
	 */
	public static function toCsv($records)
	{
		if (!is_array($records)) {
			if (method_exists($records, 'toArray')) {
				$records = $records->toArray();
			} else {
				$records = (array)$records;
			}
		}

		return FileHelper::exportCsv($records);
	}

	/**
	 * @param array $records
	 * @return string
	 */
	public static function exportCsv(array $records)
	{
		return (new CsvExport($records))->getCsvContent();
	}

	/**
	 * Parses a CSV string (see parseCsvFile)
	 *
	 * @param string $csv - the csv content to parse into an array
	 * @return array
	 */
	public static function parseCsv($csv)
	{
		$file = storage_path(uniqid() . 'parser-file.csv');

		file_put_contents($file, $csv);

		$rows = self::parseCsvFile($file);

		@unlink($file);

		return $rows;
	}

	/**
	 * Parse a CSV file into an Array
	 * Options to skip formatting
	 *
	 * @param     $file
	 * @param int $headerRowIndex
	 * @param int $firstContentRowIndex
	 * @return array
	 */
	public static function parseCsvFile($file, int $headerRowIndex = 0, int $firstContentRowIndex = 1, array $filterColumns = []): array
	{
		$rows          = [];
		$columnHeaders = [];
		$rowIndex      = 0;
		$columnCount   = 0;

		if (($handle = fopen($file, 'r')) !== false) {
			while(($rowData = fgetcsv($handle, 0, ',')) !== false) {
				if ($rowIndex == $headerRowIndex) {
					$columnHeaders = $rowData;
					$columnCount   = count($columnHeaders);
				}

				if ($rowIndex >= $firstContentRowIndex) {
					// Make sure our row data matches exactly the number of elements in our headers
					$paddedRowData = array_slice(array_pad($rowData, $columnCount, ''), 0, $columnCount);

					// Only add rows until we have reached an entirely empty row (then assume this is the end of the file)
					if (array_filter($paddedRowData, fn($value) => $value !== '')) {
						$row = array_combine($columnHeaders, $paddedRowData);

						if ($filterColumns) {
							$row = array_intersect_key($row, array_flip($filterColumns));
						}
						
						$rows[] = $row;
					} else {
						break;
					}
				}

				$rowIndex++;
			}
		}

		return $rows;
	}

	/**
	 * Create a zip file containing all the files in the given collection
	 * @param                               $name
	 * @param Collection|array|FileFacade[] $files
	 * @return ZipArchive
	 * @throws FileNotFoundException
	 */
	public static function createZipFile($name, Collection|array $files)
	{
		// Make sure the directory exists
		if (!is_dir(dirname($name))) {
			mkdir(dirname($name), 0777, true);
		}

		// Create the zip archive
		$zip = new ZipArchive();
		// Used to guarantee unique names
		$names = [];

		$zip->open($name, ZipArchive::CREATE);
		foreach($files as $file) {
			// Parse the filename and contents
			if ($file instanceof FileModel) {
				$name     = $file->filename;
				$contents = $file->getContents();
			} elseif ($file instanceof FileFacade) {
				$name     = $file->name;
				$contents = file_get_contents($file->path);
			} elseif (is_string($file)) {
				$name     = basename($file);
				$contents = file_get_contents($file);
			} else {
				throw new FileNotFoundException('File not found: ' . json_encode($file));
			}

			// Guarantee unique names for each entry
			if (in_array($name, $names)) {
				$name = substr(uuid(), 19, 4) . '-' . $name;
			}

			// Add the file to the archive
			$zip->addFromString($name, $contents);
			$names[] = $name;
		}
		// Save the Zip file to disk
		$zip->close();

		return $zip;
	}

	/**
	 * Converts a file contents to UTF-8 encoding and returns the converted contents
	 *
	 * @param $file
	 * @return false|string
	 */
	public static function convertToUtf8($file)
	{
		$contents = file_get_contents($file);
		$encoding = mb_detect_encoding($contents, 'UTF-8, ISO-8859-1, WINDOWS-1252, WINDOWS-1251', true);

		if ($encoding != 'UTF-8') {
			return iconv($encoding, 'UTF-8//IGNORE', $contents);
		}

		return $contents;
	}

	/**
	 * Converts data into YAML format
	 */
	public static function toYaml(array $data, $indentation = 2)
	{
		return (new YamlDumper($indentation))->dump($data);
	}

	/**
	 * Parses a YAML string to an associative array
	 *
	 * @param     $yaml
	 * @param int $flags
	 * @return array
	 */
	public static function parseYaml($yaml, $flags = 0)
	{
		$parser = new YamlParser();

		return $parser->parse($yaml, $flags);
	}

	/**
	 * Parses a YAML file to an associative array
	 *
	 * @param     $file
	 * @param int $flags
	 * @return array
	 */
	public static function parseYamlFile($file, $flags = 0)
	{
		$parser = new YamlParser();

		return $parser->parseFile($file, $flags);
	}

	/**
	 * Recursively remove a directory
	 *
	 * @param $dir
	 * @return void
	 */
	public static function rrmdir($dir)
	{
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach($objects as $object) {
				if ($object != '.' && $object != '..') {
					if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . '/' . $object)) {
						static::rrmdir($dir . DIRECTORY_SEPARATOR . $object);
					} else {
						unlink($dir . DIRECTORY_SEPARATOR . $object);
					}
				}
			}
			rmdir($dir);
		}
	}

	/**
	 * @param $exif
	 * @return array|float[]|int[]
	 */
	public static function getExifLocation($exif)
	{
		if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
			$lat = static::getExifGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
			$lon = static::getExifGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'W');

			return [$lat, $lon];
		}

		return [null, null];
	}

	/**
	 * @param $gps
	 * @param $direction
	 * @return float|int|string
	 */
	public static function getExifGps($gps, $direction)
	{
		$degrees   = count($gps) > 0 ? static::getExifGpsDegrees($gps[0]) : 0;
		$minutes   = count($gps) > 1 ? static::getExifGpsDegrees($gps[1]) : 0;
		$seconds   = count($gps) > 2 ? static::getExifGpsDegrees($gps[2]) : 0;
		$plusMinus = $direction == 'W' || $direction == 'S' ? -1 : 1;

		return $plusMinus * ($degrees + ($minutes / 60) + ($seconds / 3600));
	}

	/**
	 * @param $gps
	 * @return float|int|string
	 */
	public static function getExifGpsDegrees($gps)
	{
		$parts = explode('/', $gps);
		$part1 = $parts[0] ?? 0;
		$part2 = $parts[1] ?? 0;

		if ($part1 && $part2) {
			return $part1 / $part2;
		} else {
			return $part1;
		}
	}

	public static function fetchUrlContent($url): string|null
	{
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HTTPHEADER     => [
				'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
				'accept-language: en-US,en;q=0.9,la;q=0.8',
				'cache-control: max-age=0',
				'if-modified-since: Fri, 16 Feb 1990 21:13:37 GMT',
				'priority: u=0, i',
				'sec-ch-ua: "Not)A;Brand";v="99", "Google Chrome";v="127", "Chromium";v="127"',
				'sec-ch-ua-mobile: ?0',
				'sec-ch-ua-platform: "Linux"',
				'sec-fetch-dest: document',
				'sec-fetch-mode: navigate',
				'sec-fetch-site: none',
				'sec-fetch-user: ?1',
				'upgrade-insecure-requests: 1',
			],
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
			CURLOPT_ENCODING       => '',
		]);

		$response = curl_exec($ch);

		if (curl_errno($ch)) {
			throw new Exception(curl_error($ch));
		}

		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($statusCode !== 200) {
			throw new Exception("HTTP request failed. Status code: $statusCode");
		}

		return $response;
	}

	public static function cleanHtmlContent($html, $elementsToRemove = ['svg', 'style', 'head', 'script', 'img', 'video', 'audio', 'iframe', 'object', 'embed', 'source', 'track', 'canvas', 'map', 'area', 'base', 'link', 'meta', 'param']): string
	{
		$html = preg_replace("#ix:header.*?ix:header#s", '', $html);

		// Create a new DOMDocument
		$dom = new DOMDocument();

		// Suppress warnings for malformed HTML
		libxml_use_internal_errors(true);
		$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		// Create a DOMXPath object
		$xpath = new DOMXPath($dom);

		// Remove each specified element
		foreach($elementsToRemove as $element) {
			$nodes = $xpath->query("//{$element}");
			if ($nodes) {
				foreach($nodes as $node) {
					$node->parentNode->removeChild($node);
				}
			}
		}

		// Get the cleaned HTML
		return $dom->saveHTML();
	}

	public static function htmlToText($html)
	{
		// Create a new DOMDocument
		$dom = new DOMDocument();

		// Suppress warnings for malformed HTML
		libxml_use_internal_errors(true);
		$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		// Create a DOMXPath object
		$xpath = new DOMXPath($dom);


		// Process the body
		$body = $xpath->query('//body')->item(0);
		$text = $body ? FileHelper::processDomNode($body) : '';

		// Clean up the text
		$text = preg_replace('/\n{3,}/', "\n\n", $text); // Replace multiple newlines with double newlines
		$text = preg_replace('/[ \t]+/', ' ', $text);    // Replace multiple spaces with single spaces
		$text = trim($text);                             // Trim leading and trailing whitespace
		$text = StringHelper::logSafeString($text, 1000000);  // Convert to UTF-8

		return $text;
	}

	/**
	 * Recursively process a DOMNode and return the text content
	 */
	private static function processDomNode($node)
	{
		$text = '';
		if ($node->nodeType === XML_TEXT_NODE) {
			$text = $node->textContent;
		} elseif ($node->nodeType === XML_ELEMENT_NODE) {
			foreach($node->childNodes as $childNode) {
				$text .= FileHelper::processDomNode($childNode);
			}

			// Add newlines for block-level elements
			$blockElements = ['p', 'div', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li'];
			if (in_array(strtolower($node->nodeName), $blockElements)) {
				$text = "\n" . trim($text) . "\n";
			}
		}

		return $text;
	}

	public static function htmlToMarkdown($url): ?string
	{
		$html = FileHelper::fetchUrlContent($url);
		$html = FileHelper::cleanHtmlContent($html);

		$markdown = (new HtmlConverter())->convert($html);

		return FileHelper::htmlToText($markdown);
	}

	/**
	 * Check the file size (ie: Content-Length headers) for a remote URL file
	 */
	public static function getRemoteFileSize($url): bool|int
	{
		$headers = FileHelper::fastGetHeaders($url);

		if (isset($headers['Content-Length'])) {
			return (int)$headers['Content-Length'];
		}

		return false;
	}

	/**
	 * Get the headers for a remote URL file while timing out after a certain number of seconds
	 */
	public static function fastGetHeaders($url, $timeout = 3): array
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		$headers = curl_exec($ch);
		curl_close($ch);

		if (!$headers) {
			return [];
		}

		$headerList = [];
		foreach(explode("\n", $headers) as $header) {
			if (strpos($header, ':') === false) {
				continue;
			}
			[$key, $value] = explode(':', $header, 2);
			$headerList[Str::title($key)] = trim($value);
		}

		return $headerList;
	}

	/**
	 * Checks if a URL is a PDF file by either file extensions or Content-Type headers
	 */
	public static function isPdf(string $url): bool
	{
		// If the URL ends in .pdf, we can assume it's a PDF
		if (preg_match('/\.pdf$/i', $url)) {
			return true;
		}

		try {
			$headers = FileHelper::fastGetHeaders($url, 1);
		} catch(Throwable $throwable) {
			return false;
		}

		// Check if the Content-Type is 'application/pdf'
		if (isset($headers['Content-Type'])) {
			return str_contains($headers['Content-Type'], 'application/pdf');
		}

		return false;
	}

	/**
	 * @param $filename
	 * @return mixed|string
	 */
	public static function getMimeFromExtension($filename)
	{
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$mimes     = MimeTypes::getDefault()->getMimeTypes($extension);

		return $mimes[0] ?? 'application/octet-stream';
	}

	/**
	 * Cleans a URL by removing any trailing slashes, hash fragments, and decoding URI components
	 * The final URL will be the same URL, but in a standard format
	 */
	public static function normalizeUrl(string $url): string
	{
		// Trim and remove trailing characters
		$url = rtrim(trim($url), '/?\\#&');

		// Remove any hash fragments
		$url = preg_replace('/#.*/', '', $url);

		// decode URI components
		return urldecode($url);
	}

	/**
	 * Resolve an array of class names inside a directory in the App directory / namespace
	 *
	 * @param $dir
	 * @return array
	 */
	public static function getClassNamesInAppDir($dir = '')
	{
		$files = FileFacade::allFiles(app_path($dir));

		$namespace = 'App\\' . ($dir ? str_replace('/', '\\', $dir) . '\\' : '');

		$classes = [];

		foreach($files as $file) {
			$relativePath = str_replace('/', '\\', $file->getRelativePath());

			$classes[] = $namespace . ($relativePath ? $relativePath . '\\' : '') . $file->getFilenameWithoutExtension();
		}

		return $classes;
	}
}

<?php

namespace Newms87\Danx\Repositories;

use Aws\S3\S3Client;
use Exception;
use getID3;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Newms87\Danx\Exceptions\ValidationError;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Resources\StoredFileResource;
use Newms87\Danx\Services\TranscodeFileService;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;

class FileRepository
{
	const array DISPLAY_IMAGE_MIMES   = ['png', 'jpeg', 'jpg', 'gif'];
	const array DEFAULT_ALLOWED_MIMES = ['pdf', 'jpeg', 'jpg', 'png'];

	/**
	 * Creates a File resource with the given contents and options
	 */
	public function createFileWithContents(string $filepath, string $contents, array $options = []): StoredFile
	{
		$disk = $options['disk'] ?? config('filesystems.default');

		if (empty($options['mime'])) {
			$options['mime'] = FileHelper::getMimeFromExtension($filepath);
		}

		$options += [
			'disk'     => $disk,
			'filepath' => $filepath,
			'filename' => basename($filepath),
			'size'     => strlen($contents),
			'url'      => Storage::disk($disk)->url($filepath),
		];

		$file = StoredFile::create($options);

		$this->storeOnDisk($filepath, $contents, $disk);

		return $file;
	}

	/**
	 * Creates a File resource with a presigned URL that can be used to upload a file directly to AWS S3 (or local
	 * filesystem in case of local env)
	 */
	public function createFileWithUploadUrl(string $path, string $name, string $mime, array $meta = []): StoredFile
	{
		$name = FileHelper::safeFilename($name);

		// Create a unique ID to avoid duplicating / overwriting existing files
		$filepath = $path . '/' . uniqid($name . '___') . '/' . $name;

		if (!$mime) {
			$mime = FileHelper::getMimeFromExtension($name);
		}

		$storedFile = StoredFile::create([
			'disk'     => config('filesystems.default'),
			'filepath' => $filepath,
			'filename' => $name,
			'mime'     => $mime,
			'meta'     => $meta ?: [],
			'size'     => 0,
		]);

		// The URL can be a presigned URL in the case of s3 bucket uploads, so a use can upload directly to s3,
		// Otherwise the URL should just be
		if ($storedFile->disk === 's3') {
			$storedFile->url = $this->createPresignedS3Url($filepath, $mime);
		} else {
			$storedFile->url = route('file.upload-presigned-url-contents', ['storedFile' => $storedFile->id]);
		}

		$storedFile->save();

		return $storedFile;
	}

	/**
	 * Creates a presigned S3 file upload URL that can be used one time (within 30 minutes) by a 3rd party user.
	 * Intended for usage in one of our FE clients, so they can upload directly to s3 instead of sending the file
	 * through our server.
	 */
	public function createPresignedS3Url(string $filepath, string $mime): string
	{
		$s3 = new S3Client([
			'version'     => 'latest',
			'region'      => config('filesystems.disks.s3.region'),
			'credentials' => [
				'key'    => config('filesystems.disks.s3.key'),
				'secret' => config('filesystems.disks.s3.secret'),
			],
		]);

		$command = $s3->getCommand('PutObject', [
			'Bucket'      => config('filesystems.disks.s3.bucket'),
			'Key'         => $filepath,
			'ContentType' => $mime,
		]);

		// Allow the user 30 minutes to upload the file
		$request = $s3->createPresignedRequest($command, '+120 minutes');

		return (string)$request->getUri();
	}

	/**
	 * Marks a presigned file upload as completed and sets mime / size / url on the File record
	 */
	public function presignedUploadUrlCompleted(StoredFile $storedFile): array
	{
		if ($storedFile->size > 0) {
			throw new ValidationError("This presigned file upload has already been completed");
		}

		$disk             = Storage::disk($storedFile->disk);
		$storedFile->size = $disk->size($storedFile->filepath);
		$storedFile->url  = $disk->url($storedFile->filepath);
		$storedFile->save();

		if (config('danx.transcode.pdf_to_images') && $storedFile->isPdf()) {
			app(TranscodeFileService::class)->dispatch(TranscodeFileService::TRANSCODE_PDF_TO_IMAGES, $storedFile);
		}

		return StoredFileResource::make($storedFile);
	}

	/**
	 * Uploads a file and stores the file information in the database.
	 */
	public function upload(
		UploadedFile $uploadedFile,
		string       $pathPrefix = '',
		bool         $validateMime = true,
		array        $allowedMimes = null,
		             $meta = null
	): StoredFile
	{
		$this->validateFile($uploadedFile, $validateMime, $allowedMimes);

		$name = FileHelper::safeFilename($uploadedFile->getClientOriginalName());

		// Create a hash ID to avoid duplicating / overwriting existing files
		$path = $pathPrefix . '/' . uniqid($name . '___');

		$filepath = $uploadedFile->storePubliclyAs(
			$path,
			$name
		);

		if (!$filepath) {
			throw new UploadException('Could not store uploaded file.');
		}

		if (!$meta || !is_array($meta)) {
			$meta = [];
		}

		try {
			$parsedMeta = $this->parseMetaData($uploadedFile->getRealPath());
			// If we cannot JSON encode the meta-data properly, just ignore it
			if (json_encode($parsedMeta)) {
				$meta += $parsedMeta;
			}
		} catch(Exception $e) {
		}

		$file = StoredFile::make([
			'disk'     => config('filesystems.default'),
			'filepath' => $filepath,
			'filename' => $name,
			'mime'     => $uploadedFile->getMimeType(),
			'size'     => $uploadedFile->getSize(),
			'meta'     => $meta,
		]);

		if ($file->isImage()) {
			$file->exif     = $this->getExifData($uploadedFile->getRealPath());
			$file->location = $file->resolveLocation();
		}

		$file->save();

		return $file;
	}

	/**
	 * Parses the length of a video file
	 * @param $filePath
	 * @return array
	 */
	function parseMetaData($filePath): array
	{
		$meta = (new getID3)->analyze($filePath);

		return StringHelper::convertArrayToUtf8($meta);
	}

	/**
	 * Validates that an uploaded file is a file and that the mime type is accepted.
	 */
	public function validateFile(
		UploadedFile $uploadedFile,
		bool         $validateMime = true,
		array        $allowedMimes = null
	): void
	{
		if ($validateMime) {
			$allowedMimes             = $allowedMimes ?? self::DEFAULT_ALLOWED_MIMES;
			$allowedMimesLists        = implode(',', $allowedMimes);
			$validateAgainstMimeTypes = str_contains($allowedMimesLists, '/');
			if ($validateAgainstMimeTypes) {
				$mimeRule = "|mimetypes:$allowedMimesLists";
			} else {
				$mimeRule = "|mimes:$allowedMimesLists";
			}
		} else {
			$mimeRule = '';
		}

		Validator::make([
			'uploadedFile' => $uploadedFile,
		], [
			'uploadedFile' => "required|file$mimeRule",
		])->validate();
	}

	/**
	 * @param        $name       - the name of the file (combined with pathPrefix to get final location on target
	 *                           storage disk)
	 * @param        $filepath   - The path to the source file
	 * @param string $pathPrefix - The prefix to prepend to the path on the target storage disk
	 * @param null   $disk
	 * @return StoredFile
	 */
	public function saveFile($name, $filepath, string $pathPrefix = '', $disk = null): StoredFile
	{
		$disk ??= config('filesystems.default');

		// Create a hash ID to avoid duplicating / overwriting existing files
		$path = rtrim($pathPrefix, '/') . '/' . uniqid($name . '___');

		$file = new File($filepath);

		$storedPath = Storage::disk($disk)->put($path . '/' . $name, $file, 'public');

		if (!$storedPath) {
			throw new FileException('Could not store file.');
		}

		return StoredFile::create([
			'disk'     => $disk,
			'filepath' => $storedPath,
			'filename' => $name,
			'mime'     => $file->getMimeType(),
			'size'     => $file->getSize(),
		]);
	}

	/**
	 * Publicly store contents on the default storage disk. If the contents are an array, they will be JSON encoded.
	 * @param string $path
	 * @param        $contents
	 * @param null   $disk
	 * @param array  $options
	 * @return $this
	 */
	public function storeOnDisk(string $path, $contents, $disk = null, array $options = []): static
	{
		$disk = $disk ?: config('filesystems.default');

		if (!is_string($contents)) {
			$contents = json_encode($contents);
		}

		Storage::disk($disk)->put($path, $contents, $options);

		return $this;
	}

	/**
	 * Retrieve and potentially JSON decode a file from the default storage disk
	 *
	 * @param      $path
	 * @param null $disk
	 * @return mixed|string|null
	 */
	public function getFromDisk($path, $disk = null)
	{
		$contents = Storage::disk($disk ?: config('filesystems.default'))->get($path);

		$decodedContent = json_decode($contents, true);

		if ($decodedContent !== null) {
			return $decodedContent;
		} else {
			return $contents;
		}
	}

	/**
	 * @param $filepath
	 * @return array|bool
	 */
	public function getExifData($filepath)
	{
		if (file_exists($filepath)) {
			return @mb_convert_encoding(exif_read_data($filepath), 'UTF-8', 'UTF-8');
		}

		return false;
	}
}

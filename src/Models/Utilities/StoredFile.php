<?php

namespace Newms87\Danx\Models\Utilities;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Traits\SerializesDates;
use Newms87\Danx\Traits\UuidModelTrait;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class StoredFile extends Model implements AuditableContract
{
	use
		Auditable,
		SerializesDates,
		SoftDeletes,
		UuidModelTrait;

	const
		MIME_3G2 = 'video/3gpp2',
		MIME_3GP = 'video/3gpp',
		MIME_EPS = 'image/x-eps',
		MIME_EXCEL = 'application/vnd.ms-excel',
		MIME_GIF = 'image/gif',
		MIME_HEIC = 'image/heic',
		MIME_HTML = 'text/html',
		MIME_ICON = 'image/x-icon',
		MIME_JPEG = 'image/jpeg',
		MIME_JSON = 'application/json',
		MIME_M4V = 'video/x-m4v',
		MIME_MP2T = 'video/mp2t',
		MIME_MP4 = 'video/mp4',
		MIME_MPEG = 'video/mpeg',
		MIME_MS_OFFICE = 'application/vnd.ms-office',
		MIME_OCTET = 'application/octet-stream',
		MIME_OGG = 'video/ogg',
		MIME_OPEN_WORD = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		MIME_PDF = 'application/pdf',
		MIME_PHOTOSHOP = 'image/vnd.adobe.photoshop',
		MIME_PNG = 'image/png',
		MIME_QUICKTIME = 'video/quicktime',
		MIME_SVG = 'image/svg',
		MIME_TEXT = 'text/plain',
		MIME_TIFF = 'image/tiff',
		MIME_WEBM = 'video/webm',
		MIME_WEBP = 'image/webp',
		MIME_OPEN_SHEET = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		MIME_ZIP = 'application/zip';

	const IMAGE_MIMES = [
		self::MIME_EPS,
		self::MIME_GIF,
		self::MIME_HEIC,
		self::MIME_ICON,
		self::MIME_JPEG,
		self::MIME_PHOTOSHOP,
		self::MIME_PNG,
		self::MIME_SVG,
		self::MIME_TIFF,
		self::MIME_WEBP,
	];

	const VIDEO_MIMES = [
		self::MIME_3G2,
		self::MIME_3GP,
		self::MIME_M4V,
		self::MIME_MP2T,
		self::MIME_MP4,
		self::MIME_MPEG,
		self::MIME_OGG,
		self::MIME_QUICKTIME,
		self::MIME_WEBM,
	];

	protected $table   = 'stored_files';
	protected $keyType = 'string';

	protected $fillable = [
		'disk',
		'filename',
		'filepath',
		'url',
		'mime',
		'size',
		'exif',
		'meta',
	];

	protected $casts = [
		'exif'     => 'json',
		'meta'     => 'json',
		'location' => 'json',
	];

	/**
	 * Synchronize the list of files to the instance type. Disassociates any existing
	 * relationships, and attaches the existing file ID's to $id for $type
	 *
	 * @param      $id
	 * @param      $type
	 * @param      $files
	 * @return bool
	 */
	public static function sync($id, $type, $files)
	{
		self::unguard();
		// Remove any old files currently associated to the type instance
		self::where('storable_id', $id)
			->where('storable_type', $type)
			->update([
				'storable_id'   => '',
				'storable_type' => '',
			]);

		// Associate the new files to the type instance
		self::whereIn('id', $files)
			->update([
				'storable_id'   => $id,
				'storable_type' => $type,
			]);
		self::reguard();

		return true;
	}

	/**
	 * Handle CUD events
	 */
	public static function boot()
	{
		parent::boot();

		static::saving(function (self $file) {
			if (!$file->url) {
				$file->url = $file->storageDisk()->url($file->filepath);
			}
		});
	}

	/**
	 * @return MorphTo
	 */
	public function storable()
	{
		return $this->morphTo();
	}

	/**
	 * @return HasMany
	 */
	public function transcodes(): HasMany|StoredFile
	{
		return $this->hasMany(StoredFile::class, 'original_stored_file_id');
	}

	/**
	 * @return Filesystem|FilesystemAdapter
	 */
	public function storageDisk()
	{
		return Storage::disk($this->disk);
	}

	/**
	 * @return string|null
	 */
	public function getContents()
	{
		return $this->storageDisk()->get($this->filepath);
	}

	/**
	 * Writes any changes to the file's contents to the new filepath (if given)
	 * and updates the file's stored size.
	 * NOTE: This does NOT save the file! If you do not save it, the file will still reference the original
	 *
	 * @param string $contents
	 * @param null   $filePath
	 * @return mixed
	 */
	public function write($contents, $filePath = null, $public = true)
	{
		if ($filePath) {
			$this->filepath = $filePath;
		}

		// Save it on the disk
		$this->storageDisk()->put(
			$this->filepath,
			$contents,
			$public ? 'public' : null
		);

		// Update the related fields to content changes
		$this->url  = $this->storageDisk()->url($this->filepath);
		$this->size = $this->storageDisk()->size($this->filepath);

		// Return the URL pointing to the formatted image stored on the disk
		return $this;
	}

	/**
	 * @param $path
	 * @return string
	 */
	public function getUrl($path)
	{
		return $this->storageDisk()->url($path);
	}

	/**
	 * @param $mimes
	 * @return bool
	 */
	public function isMime($mimes)
	{
		if (!is_array($mimes)) {
			$mimes = [$mimes];
		}

		return in_array($this->mime, $mimes);
	}

	/**
	 * Checks if the file is an image format
	 *
	 * @return bool
	 */
	public function isImage()
	{
		return in_array($this->mime, static::IMAGE_MIMES);
	}

	/**
	 * Checks if the file is an image format (or a format renderable as an image)
	 * @return bool
	 */
	public function hasPreviewImage()
	{
		return $this->isImage() || $this->isPdf();
	}

	/**
	 * Checks if the file is video format
	 *
	 * @return bool
	 */
	public function isVideo()
	{
		return in_array($this->mime, static::VIDEO_MIMES);
	}

	/**
	 * Checks if the file is a PDF
	 *
	 * @return bool
	 */
	public function isPdf()
	{
		return $this->mime === static::MIME_PDF;
	}

	/**
	 * @return string|string[]
	 */
	public function extension()
	{
		return pathinfo($this->filepath, PATHINFO_EXTENSION);
	}

	/**
	 * @return string A human-readable version of the number of bytes in this file
	 */
	public function getHumanSizeAttribute()
	{
		return FileHelper::getHumanSize($this->size);
	}

	/**
	 * @return bool|void|null
	 */
	public function forceDelete()
	{
		// Delete the actual stored file
		$this->storageDisk()->delete($this->filepath);

		return parent::forceDelete();
	}

	/**
	 * @return array
	 */
	public function resolveLocation()
	{
		$latitude  = null;
		$longitude = null;

		if ($this->exif) {
			[$latitude, $longitude] = FileHelper::getExifLocation($this->exif);
		}

		if ($latitude === null || $longitude === null) {
			$latitude  = $this->meta['latitude'] ?? null;
			$longitude = $this->meta['longitude'] ?? null;
		}

		if ($latitude !== null && $longitude !== null) {
			return [
				'latitude'  => $latitude,
				'longitude' => $longitude,
			];
		}

		return [];
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return "<StoredFile ($this->id) $this->filename mime='$this->mime' size='$this->human_size'>";
	}
}

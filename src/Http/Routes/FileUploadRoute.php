<?php

namespace Newms87\Danx\Http\Routes;


use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;
use Newms87\Danx\Http\Controllers\FileController;

class FileUploadRoute extends Route
{
	/**
	 * @param string $name
	 * @return RouteRegistrar
	 */
	public static function routes(string $name = 'file-upload'): RouteRegistrar
	{
		// Strict naming / prefixing rules to ensure consistency
		$prefix = str_replace('.', '/', $name);

		return static::prefix($prefix)->withoutMiddleware([VerifyCsrfToken::class])->group(function () use ($name) {
			self::get('presigned-upload-url', [FileController::class, 'presignedUploadUrl'])
				->name('file.presigned-upload-url');
			self::post('upload-presigned-url-contents/{storedFile}',
				[FileController::class, 'uploadPresignedUrlContents'])
				->name('file.upload-presigned-url-contents');
			self::post('presigned-upload-url-completed/{storedFile}', [FileController::class, 'presignedUploadUrlCompleted'])
				->name('file.presigned-upload-url-completed');
			self::get('refresh/{storedFile}', [FileController::class, 'refresh'])
				->name('file.refresh');
		});
	}
}

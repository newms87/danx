<?php

namespace Newms87\Danx\Models\Audit;

use Error;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Services\Error\RetryableErrorChecker;
use Throwable;

class ErrorLog extends Model
{
	use HasDebugLogging;

	const int
		DEBUG = 100,
		INFO = 200,
		NOTICE = 250,
		WARNING = 300,
		ERROR = 400,
		CRITICAL = 500,
		ALERT = 550,
		EMERGENCY = 600;

	const int MAX_MESSAGE_SIZE = 512;

	// Cap these messages to 10 MB
	const int MAX_FULL_MESSAGE_SIZE = 1024 * 1024 * 10;

	protected $table = 'error_logs';

	protected $guarded = [
		'id',
		'created_at',
		'updated_at',
	];

	protected $casts = [
		'last_seen_at' => 'datetime',
		'stack_trace'  => 'json',
	];

	/**
	 * @param $level
	 * @return string
	 */
	public static function getLevelName($level): string
	{
		return match ($level) {
			self::DEBUG => 'DEBUG',
			self::INFO => 'INFO',
			self::NOTICE => 'NOTICE',
			self::WARNING => 'WARNING',
			self::ERROR => 'ERROR',
			self::CRITICAL => 'CRITICAL',
			self::ALERT => 'ALERT',
			self::EMERGENCY => 'EMERGENCY',
		};
	}

	/**
	 * Convert a level name to an integer
	 */
	public static function getLevelInt(string $levelName): int
	{
		return match ($levelName) {
			'DEBUG' => self::DEBUG,
			'INFO' => self::INFO,
			'NOTICE' => self::NOTICE,
			'WARNING' => self::WARNING,
			'ERROR' => self::ERROR,
			'CRITICAL' => self::CRITICAL,
			'ALERT' => self::ALERT,
			'EMERGENCY' => self::EMERGENCY,
			default => 0,
		};
	}

	/**
	 * @param Exception|Error $exception
	 * @return array
	 */
	public static function getStackTrace(Exception|Error $exception)
	{
		$trace = [];

		foreach($exception->getTrace() as $entry) {
			$trace[] = [
				'file'     => $entry['file'] ?? null,
				'line'     => $entry['line'] ?? null,
				'class'    => $entry['class'] ?? null,
				'function' => $entry['function'] ?? null,
			];
		}

		return $trace;
	}

	public static function shouldLogErrors(): bool
	{
		$command = $_SERVER['argv'][1] ?? null;

		if (app()->runningInConsole() && str_contains($command, 'migrate')) {
			return false;
		}

		return true;
	}

	/**
	 * @param int    $level
	 * @param string $message
	 * @param int    $code
	 * @param array  $data
	 * @return ErrorLog|null
	 */
	public static function logErrorMessage(int $level, string $message, int $code = 0, array $data = []): ?ErrorLog
	{
		if (!static::shouldLogErrors()) {
			return null;
		}

		// Ignore logging anything below ERROR level
		if ($level < ErrorLog::ERROR) {
			return null;
		}

		$errorLog = ErrorLog::make([
			'error_class'        => 'Message',
			'code'               => $code,
			'level'              => $level,
			'message'            => substr($message, 0, self::MAX_MESSAGE_SIZE),
			'send_notifications' => true,
		]);

		return self::log($errorLog, $message, $data, false);
	}

	/**
	 * @param int                       $level
	 * @param Throwable|Exception|Error $exception
	 * @param array                     $data
	 * @param ErrorLog|null             $parent
	 * @return ErrorLog|null
	 */
	public static function logException(int $level, Throwable|Exception|Error $exception, array $data = [], ErrorLog $parent = null): ?ErrorLog
	{
		if (!static::shouldLogErrors()) {
			return null;
		}

		// Ignore logging INFO or lower
		if ($level <= ErrorLog::INFO) {
			return null;
		}

		// Override the exception logging level if it is set
		if (isset($exception::$level)) {
			$level = self::getLevelName($exception::$level);
		}

		$message = StringHelper::safeConvertToUTF8($exception->getMessage());

		$errorLog = ErrorLog::make([
			'error_class'        => $exception::class,
			'code'               => $exception->getCode(),
			'level'              => $level,
			'message'            => substr($message, 0, self::MAX_MESSAGE_SIZE),
			'file'               => $exception->getFile(),
			'line'               => $exception->getLine(),
			'stack_trace'        => self::getStackTrace($exception),
			'last_seen_at'       => now(),
			'count'              => 1,
			'send_notifications' => true,
		]);

		// Attach the parent entry if one exists
		if ($parent) {
			$errorLog->parent()->associate($parent);
			$errorLog->root()->associate($parent->root_id ?: $parent);
		}

		// Check if the exception is retryable
		$isRetryable = RetryableErrorChecker::isJobRetryable($exception);

		$errorLog = self::log($errorLog, $message, $data, $isRetryable);

		// If this is a new error log entry, lets map out the children
		if ($previous = $exception->getPrevious()) {
			self::logException($level, $previous, [], $errorLog);
		}

		if ($errorLog) {
			static::logError("$errorLog: $errorLog->message", ['exception' => $exception]);
		}

		return $errorLog;
	}

	/**
	 * @param ErrorLog $errorLog
	 * @param string   $message
	 * @param array    $data
	 * @param bool     $isRetryable
	 * @return ErrorLog|null
	 */
	public static function log(ErrorLog $errorLog, string $message, array $data = [], bool $isRetryable = false): ?ErrorLog
	{
		$errorLog->hash = $errorLog->generateHash();

		try {
			$existingErrorLog = ErrorLog::where('hash', $errorLog->hash)->first();

			if ($existingErrorLog) {
				$errorLog = $existingErrorLog;
				$errorLog->count++;
			} else {
				$errorLog->count = 1;
			}

			$errorLog->last_seen_at = now();

			$errorLog->save();
		} catch(Exception $exception) {
			// This is likely due to the error_logs table missing, so don't attempt to continue
			static::logError('Error saving to error log table: ' . $exception->getMessage());

			return null;
		}

		$errorLog->addEntry($message, $data, $isRetryable);

		return $errorLog;
	}

	/**
	 * @param null $data
	 */
	public function addEntry($message, $data = null, bool $isRetryable = false): void
	{
		$message = StringHelper::logSafeString($message, self::MAX_FULL_MESSAGE_SIZE);

		$this->entries()->create([
			'user_id'          => user()?->id,
			'audit_request_id' => AuditDriver::getAuditRequest()?->id,
			'message'          => substr($message, 0, self::MAX_MESSAGE_SIZE),
			'full_message'     => $message,
			'data'             => $data ?: null,
			'is_retryable'     => $isRetryable,
		]);
	}

	public function generateHash(): string
	{
		if ($this->stack_trace) {
			$id = json_encode($this->stack_trace);
		} else {
			$id = explode(':', $this->message)[0];
		}

		return md5(base64_encode("$this->error_class:::$this->level:::$this->code:::$this->file:::$this->line:::$id"));
	}

	public function entries(): HasMany|ErrorLogEntry
	{
		return $this->hasMany(ErrorLogEntry::class);
	}

	/**
	 * The parent error of this error
	 */
	public function parent(): BelongsTo|ErrorLog
	{
		return $this->belongsTo(ErrorLog::class, 'parent_id');
	}

	/**
	 * All the children of this error
	 */
	public function children(): HasMany|ErrorLog
	{
		return $this->hasMany(ErrorLog::class, 'parent_id');
	}

	/**
	 * A flat list of all children
	 */
	public function chain(): HasMany|ErrorLog
	{
		return $this->hasMany(ErrorLog::class, 'root_id');
	}

	/**
	 * The root Error entry in the chain
	 */
	public function root(): BelongsTo|ErrorLog
	{
		return $this->belongsTo(ErrorLog::class, 'root_id');
	}

	public function __toString(): string
	{
		return "<ErrorLog id='$this->id' level='$this->level' class='$this->error_class'>";
	}
}

<?php

namespace Newms87\DanxLaravel\Models\Audit;

use Error;
use Exception;
use Newms87\DanxLaravel\Audit\AuditDriver;
use Newms87\DanxLaravel\Helpers\StringHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorLog extends Model
{
	const DEBUG = 100,
		INFO = 200,
		NOTICE = 250,
		WARNING = 300,
		ERROR = 400,
		CRITICAL = 500,
		ALERT = 550,
		EMERGENCY = 600;

	const MAX_MESSAGE_SIZE = 512;

	// Cap these messages to 10 MB
	const MAX_FULL_MESSAGE_SIZE = 1024 * 1024 * 10;

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
	 * @param                           $level
	 * @param Throwable|Exception|Error $exception
	 * @param array                     $data
	 * @param ErrorLog|null             $parent
	 * @return ErrorLog|Model|object
	 */
	public static function logException($level, Throwable|Exception|Error $exception, array $data = [], ErrorLog $parent = null)
	{
		// Ignore logging Warnings or lower
		if (isset($exception::$level) && $exception::$level <= 300 || $level === 'WARNING') {
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

		$errorLog = self::log($errorLog, $message, $data);

		// If this is a new error log entry, lets map out the children
		if ($previous = $exception->getPrevious()) {
			self::logException($level, $previous, [], $errorLog);
		}

		Log::error(
			$errorLog->level . " " . basename($errorLog) . " ($errorLog->code)\n\n" .
			"$errorLog->message\n\n" .
			"$errorLog->file:$errorLog->line" .
			(config('danx.logging.output_exception_traces') ? "\n\n" . $exception->getTraceAsString() . "\n" : '')
		);

		return $errorLog;
	}

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

	/**
	 * @param       $level
	 * @param       $message
	 * @param int   $code
	 * @param array $data
	 * @return ErrorLog|Model
	 */
	public static function logErrorMessage($level, $message, int $code = 0, array $data = [])
	{
		// Ignore logging Warnings
		if ($level === 'WARNING') {
			return null;
		}

		$errorLog = ErrorLog::make([
			'error_class'        => 'Message',
			'code'               => $code,
			'level'              => $level,
			'message'            => substr($message, 0, self::MAX_MESSAGE_SIZE),
			'send_notifications' => true,
		]);

		return self::log($errorLog, $message, $data);
	}

	/**
	 * @param ErrorLog $errorLog
	 * @param string   $message
	 * @param array    $data
	 * @return ErrorLog|Builder|Model|object
	 */
	public static function log(ErrorLog $errorLog, string $message, array $data = [])
	{
		$errorLog->hash = $errorLog->generateHash();

		try {
			$existingErrorLog = ErrorLog::where('hash', $errorLog->hash)->first();
		} catch(Exception $exception) {
			// Fail silently - this is likely due to the error_logs table missing, so don't attempt to continue
			Log::channel('slack-custom')->error('Error reading from error log: ' . $exception->getMessage());

			return null;
		}

		if ($existingErrorLog) {
			$errorLog = $existingErrorLog;
			$errorLog->count++;
		} else {
			$errorLog->count = 1;
		}

		$errorLog->last_seen_at = now();

		try {
			$errorLog->save();
		} catch(Exception $exception) {
			// Fail silently - this is likely due to the error_logs table missing, so don't attempt to continue
			Log::channel('slack-custom')->error('Error saving to error log table: ' . $exception->getMessage());

			return null;
		}

		$errorLog->addEntry($message, $data);

		return $errorLog;
	}

	/**
	 * @param null $data
	 */
	public function addEntry($message, $data = null): void
	{
		$message = StringHelper::logSafeString($message, self::MAX_FULL_MESSAGE_SIZE);

		$this->entries()->create([
			'user_id'          => user()?->id,
			'audit_request_id' => AuditDriver::getAuditRequest()?->id,
			'message'          => substr($message, 0, self::MAX_MESSAGE_SIZE),
			'full_message'     => $message,
			'data'             => $data ?: null,
		]);
	}

	/**
	 * @return string
	 */
	public function generateHash(): string
	{
		if ($this->stack_trace) {
			$id = json_encode($this->stack_trace);
		} else {
			$id = explode(':', $this->message)[0];
		}

		return md5(base64_encode("$this->error_class:::$this->level:::$this->code:::$this->file:::$this->line:::$id"));
	}

	/**
	 * @return HasMany|ErrorLogEntry[]
	 */
	public function entries()
	{
		return $this->hasMany(ErrorLogEntry::class);
	}

	/**
	 * @return BelongsTo|ErrorLog
	 */
	public function parent()
	{
		return $this->belongsTo(ErrorLog::class, 'parent_id');
	}

	/**
	 * @return HasMany|ErrorLog[]
	 */
	public function children()
	{
		return $this->hasMany(ErrorLog::class, 'parent_id');
	}

	/**
	 * A flat list of all children
	 *
	 * @return HasMany|ErrorLog[]
	 */
	public function chain()
	{
		return $this->hasMany(ErrorLog::class, 'root_id');
	}

	/**
	 * The root Error entry in the chain
	 *
	 * @return BelongsTo
	 */
	public function root()
	{
		return $this->belongsTo(ErrorLog::class, 'root_id');
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return "ErrorLog ($this->id): $this->level $this->code $this->error_class";
	}
}

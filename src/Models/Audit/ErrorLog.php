<?php

namespace Newms87\Danx\Models\Audit;

use Error;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
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

	/**
	 * Log-record context key marking a line whose error this class has already recorded.
	 * AuditLogHandler skips recording such a line; see logException().
	 */
	const string RECORDED_CONTEXT_KEY = 'error_log_recorded';

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
	 * Normalize any level representation to the integer every comparison and every
	 * error_logs.level value in this class uses: a Monolog\Level enum (its ->value), an int,
	 * a numeric string, or a level name in any case ('error', 'ERROR').
	 *
	 * Exists because comparing a Monolog\Level enum against an int is always false in PHP,
	 * and passing a level NAME where an int is typed throws a TypeError — both of which have
	 * shipped here. Convert at the boundary, then compare ints.
	 */
	public static function normalizeLevel(int|string|Level $level): int
	{
		if ($level instanceof Level) {
			return $level->value;
		}

		if (is_numeric($level)) {
			return (int)$level;
		}

		return self::getLevelInt(strtoupper($level));
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

		// Override the exception logging level if it is set. It must stay an int: it is stored
		// as error_logs.level and passed back into this int-typed method for the previous
		// exception, so a level NAME here would throw a TypeError on any chained exception.
		if (isset($exception::$level)) {
			$level = self::normalizeLevel($exception::$level);
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

		// Write the line to the log channel, so an exception recorded by a direct call
		// (AuditingMiddleware, ActionController) still shows in the request's log. The context
		// flag tells AuditLogHandler it is already recorded: without it the handler recorded
		// it again, doubling `count` and the entries and, for a chained link logged at a level
		// other than ERROR, adding a parentless orphan row.
		if ($errorLog) {
			Log::error("[ErrorLog] $errorLog: $errorLog->message", [
				'exception'                => $exception,
				self::RECORDED_CONTEXT_KEY => true,
			]);
		}

		return $errorLog;
	}

	/**
	 * @param ErrorLog $errorLog
	 * @param string   $message
	 * @param array    $data
	 * @param bool     $isRetryable
	 * @return ErrorLog|null
	 *
	 * SG-809: on a hash match, `message` used to stay whatever the FIRST occurrence ever
	 * recorded under that hash happened to be — `count` and `last_seen_at` moved forward,
	 * the displayed text never did. A grouped entry can have real variety among its
	 * occurrences (see generateHash()'s docblock — grouping by normalized shape still lets
	 * genuinely different keys/records share one row on purpose), so a reader was shown one
	 * arbitrary, increasingly stale sample as if it were the whole story. That is what
	 * produced a false bug report off a message that had not been true in days. `message` is
	 * now overwritten on every occurrence, so it always reflects the MOST RECENT real
	 * occurrence — actionable for "is this still happening" — while every individual
	 * occurrence's own full text is separately and permanently preserved on its own
	 * ErrorLogEntry regardless (see addEntry()).
	 */
	public static function log(ErrorLog $errorLog, string $message, array $data = [], bool $isRetryable = false): ?ErrorLog
	{
		$errorLog->hash = $errorLog->generateHash();

		try {
			$existingErrorLog = ErrorLog::where('hash', $errorLog->hash)->first();

			if ($existingErrorLog) {
				// Keep the incoming occurrence's own message (already MAX_MESSAGE_SIZE-capped
				// by the caller) before $errorLog is replaced by the existing row below.
				$latestMessage = $errorLog->message;

				$errorLog = $existingErrorLog;
				$errorLog->count++;
				$errorLog->message = $latestMessage;
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

	/**
	 * The grouping key: every occurrence with the same hash is one ErrorLog row, its `count`
	 * incremented and an entry added.
	 *
	 * An exception is identified by its stack trace. A message has no trace, so it is
	 * identified by its FULL text, after normalizeMessageForGrouping() has replaced every id,
	 * name and number with a placeholder — so the same message-shape about two different
	 * records is one ErrorLog, not one per record, while two messages that genuinely differ in
	 * wording (including anything after the first colon) stay apart.
	 *
	 * SG-809: this used to key on only the text BEFORE the first colon
	 * (`explode(':', ...)[0]`), on the assumption that a colon always separates a fixed label
	 * from the variable part a record-level message appends after it. That assumption is false
	 * for the equally common shape "LABEL: <the entire distinguishing part>" — there the colon
	 * sits between the label and everything that makes one occurrence different from another,
	 * so truncating at it discarded the one thing worth grouping BY and merged unrelated
	 * occurrences together. Measured: one such row (lock-release failures logged as
	 * "RELEASE-BY-OWNER-FAILED: <key>") had merged 186 distinct real keys — spanning two
	 * unrelated task-worker workflows and an unrelated schema-definition publish job — under a
	 * single hash, and (see log() below) its displayed message stayed frozen on whichever of
	 * those unrelated keys happened to merge in first. Keying on the full normalized message
	 * instead fixes this without losing the original de-duplication this method exists for:
	 * two occurrences whose only difference is an id/uuid/number/quoted value still normalize
	 * to the identical string and still merge into one row.
	 */
	public function generateHash(): string
	{
		if ($this->stack_trace) {
			$id = json_encode($this->stack_trace);
		} else {
			$id = static::normalizeMessageForGrouping($this->message);
		}

		return md5(base64_encode("$this->error_class:::$this->level:::$this->code:::$this->file:::$this->line:::$id"));
	}

	/**
	 * A message with its variable parts replaced by placeholders, for grouping.
	 *
	 * "[ApiLog] Failed <ApiLog id='19149' GET 401 https://…>: …" and the same line for ApiLog
	 * 19150 must be one ErrorLog. Before this, the key was the raw text before the first
	 * colon, and nearly every message carries a record name, id or URL before any colon —
	 * so each occurrence became its own row and `count` never grew.
	 *
	 * Replaced, in order: a model's __toString() (`<Class …>` keeps only the class), UUIDs,
	 * quoted values, long hex ids, then every remaining number. Unquoted words are left alone,
	 * so wording that differs — including an unquoted object type — stays apart.
	 */
	public static function normalizeMessageForGrouping(string $message): string
	{
		$placeholders = [
			'/<([A-Za-z_][\w\\\\]*)\s[^<>]*>/'                                         => '<$1>',
			'/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i'    => '{uuid}',
			// A quote opens where no word character precedes it and closes where none follows,
			// so the apostrophes in "can't" and "model's" are not quotes and 'O'Brien' is one value.
			"/(?<!\\w)'.*?'(?!\\w)/"                                                   => "'?'",
			'/(?<!\w)".*?"(?!\w)/'                                                     => '"?"',
			'/`.*?`/'                                                                  => '`?`',
			// At least one digit, so an ordinary word made of hex letters survives.
			'/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i'                                      => '{hex}',
			'/\d+(?:\.\d+)*/'                                                          => 'N',
		];

		return preg_replace(array_keys($placeholders), array_values($placeholders), $message);
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

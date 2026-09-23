<?php

namespace Newms87\Danx\Audit;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Jobs\Job;
use Newms87\Danx\Models\Audit\Audit;
use Newms87\Danx\Models\Audit\AuditRequest;
use OwenIt\Auditing\Contracts\Audit as AuditContract;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver as AuditDriverContract;
use OwenIt\Auditing\Exceptions\AuditingException;

class AuditDriver implements AuditDriverContract
{
	use HasDebugLogging;

	// The singleton session / request objects for this request
	const array SENSITIVE_FIELDS = [
		'/password/i',
	];

	const array IGNORE_USER_AGENTS = [
		'kube-probe/1.10' => 'kube-probe',
	];

	const string SESSION_HEADER     = 'Session-UUID';
	const string SESSION_COOKIE     = 'session-uuid';
	const string FINGERPRINT_COOKIE = 'fingerprint';

	/**
	 * The current process's "audit request" — every debug log line, ApiLog/ErrorLog row and
	 * audited model write attributes itself to whichever {@see AuditRequest} lives here.
	 *
	 * ## Why this stays PUBLIC (SG-858)
	 *
	 * A getter/setter pair would add no safety: PHP has no way to make a static property
	 * write-restricted to "this class and nothing else" without breaking the exact usage this
	 * property exists for. It is a deliberate, load-bearing seam — not an oversight — read and
	 * assigned directly, outside this class, by:
	 *
	 * - {@see \Newms87\Danx\Jobs\Job} — reattaches it at every queued-job boundary so a job's
	 *   audit trail survives serialization across the queue.
	 * - {@see \Newms87\Danx\Support\ProcessFork} — a forked child clears it so it never
	 *   inherits the parent's audit request by accident.
	 * - {@see \Newms87\Danx\Support\Heartbeat} and {@see \Newms87\Danx\Support\SignalHandler} —
	 *   read it directly to attribute a heartbeat/signal event to the request that is currently
	 *   running when neither owns that request's lifecycle.
	 * - {@see \Newms87\Danx\Console\Commands\TestHeartbeatCommand} — drives it directly to
	 *   simulate a running request/child/heartbeat sequence for manual verification.
	 * - Every consuming application's test suite. Verified 2026-09-22: 30 direct assignments
	 *   across 15 test files in gpt-manager alone (`AuditDriver::$auditRequest = null` to reset
	 *   between tests, or `= $auditRequest` to seed a known one), plus 11 more across danx's own
	 *   src/tests. A test fabricating "the request currently running" needs synchronous,
	 *   unmediated control over exactly this value in `setUp()`/`tearDown()` — a setter method
	 *   changes nothing about what the test does, it only adds a parentheses pair every one of
	 *   those ~40+ call sites, in every consuming repo, would have to grow.
	 *
	 * What actually needed fixing was never "who can assign this" — it is that deleting the row
	 * this names left the value behind. Every delete of `audit_request` rows now goes through
	 * {@see deleteAuditRequests()}, which releases this when its row is gone; see {@see
	 * auditRequestExists()} for the measured cost of checking on every read instead.
	 */
	public static ?AuditRequest $auditRequest = null;

	public static float $startTime = 0;

	public static function startTimer(): void
	{
		self::$startTime = microtime(true);
	}

	/**
	 * Add an audit log entry
	 *
	 * @param      $type
	 * @param      $message
	 * @param null $data
	 */
	public static function log($type, $message, $data = null): void
	{
		$entry = (object)[
			'user_type'      => '',
			'auditable_id'   => '',
			'auditable_type' => '',
			'old_values'     => $message,
			'new_values'     => $data,
			'tags'           => 'custom',
		];

		self::createEntry($type, $entry);
	}

	/**
	 * Creates an AuditRequest Log entry with the specified URL
	 *
	 * @param $url
	 *
	 * @return AuditRequest|null
	 */
	public static function logRequest($url): ?AuditRequest
	{
		return self::getAuditRequest($url);
	}

	/**
	 * This method should only be called when Laravel is about to terminate execution.
	 * Handles filling in response / post execution data
	 */
	public static function terminate($response = null)
	{
		// Make sure this gets logged by creating an AuditRequest
		// object if it has not already been created
		$auditRequest = self::getAuditRequestOrIgnore();

		if ($auditRequest) {
			$auditRequest->update([
				'time'     => microtime(true) - self::$startTime,
				'response' => $response ? self::getResponse($response) : null,
			]);

			if ($response instanceof Response || $response instanceof JsonResponse) {
				$response->header('X-Audit-Request-Id', $auditRequest->id);
				$response->header('X-Audit-Request-Url', app_url("/audit-requests/$auditRequest->id/request"));

				$errorsCount = $auditRequest->errorLogEntries()->count();
				if ($errorsCount > 0) {
					$response->header('X-Audit-Error-Log-Count', $errorsCount);
				}
			}
		}

		return $response;
	}

	/**
	 * Typically used by the terminate method, checks if the AuditRequest has already been created,
	 * if it has not, this implies there have been no data changes yet,
	 * so lets check to see if we should ignore this request all together (ie: this is a cron job
	 * running every minute)
	 */
	public static function getAuditRequestOrIgnore()
	{
		$blockList = [
			'*/audit*',
			'*__clockwork*',
		];
		
		if (!self::$auditRequest && (app()->runningInConsole() || request()->fullUrlIs($blockList))) {
			return null;
		}

		return self::getAuditRequest();
	}

	/**
	 * Returns relevant response data
	 *
	 * @param Response $response
	 * @return array
	 */
	public static function getResponse($response): array
	{
		//XXX: apparently laravel 5.4 has not updated all the response classes to use the new status / content methods (eg: the BinaryFileResponse).
		$responseStatus = method_exists($response, 'status') ? $response->status() : $response->getStatusCode();

		$content = method_exists($response, 'content') ? $response->content() : $response->getContent();

		$responseSize        = strlen($content);
		$responseHeadersSize = strlen($response->headers);
		$requestSize         = strlen(request()->getContent());
		$requestHeadersSize  = strlen(request()->headers);
		$base64PayloadSize   = strlen(base64_encode($content)) + strlen(base64_encode(request()->getContent())) + strlen(base64_encode(request()->headers)) + strlen(base64_encode($response->headers));
		$payloadSize         = $responseSize + $responseHeadersSize + $requestSize + $requestHeadersSize;

		return [
			'headers'               => $response->headers->all(),
			'status'                => $responseStatus,
			'length'                => $responseSize,
			'response_size'         => $responseSize,
			'response_headers_size' => $responseHeadersSize,
			'request_size'          => strlen(request()->getContent()),
			'request_headers_size'  => $requestHeadersSize,
			'payload_size'          => $payloadSize,
			'base64_response_size'  => strlen(base64_encode($content)),
			'base64_payload_size'   => $base64PayloadSize,
			'max_memory_used'       => memory_get_peak_usage(true),
		];
	}

	/**
	 * Perform an audit.
	 *
	 * @param Auditable $model
	 * @return AuditContract
	 * @throws AuditingException
	 */
	public function audit(Auditable $model): AuditContract
	{
		$data = (object)$model->toAudit();

		return self::createEntry($data->event, $data, $model) ?? Audit::make();
	}

	/**
	 * Commits an audit entry to the DB
	 *
	 * @param                      $event
	 * @param                      $data
	 * @param Model|Auditable|null $model
	 * @return Audit|Model
	 */
	public static function createEntry($event, $data, Model|Auditable $model = null): ?Audit
	{
		// If auditing is disabled, do nothing
		if (!config('danx.audit.enabled')) {
			return null;
		}

		$data = (object)$data;

		$auditRequest = self::getAuditRequest();

		// If the AuditRequest does not exist, then we cannot create a record
		if (!$auditRequest) {
			return null;
		}

		$cleanData = self::cleanValues($data, $model);

		try {
			// Nothing to record for the updated event
			if ($event === 'updated' && empty($cleanData->old_values) && empty($cleanData->new_values)) {
				return null;
			}

			return Audit::create([
				'audit_request_id' => $auditRequest->id,
				'user_id'          => $auditRequest->user_id,
				'event'            => $event,
				'auditable_id'     => $data->auditable_id,
				'auditable_type'   => $data->auditable_type,
				'old_values'       => $cleanData->old_values,
				'new_values'       => $cleanData->new_values,
				'tags'             => $data->tags,
			]);
		} catch(Exception $e) {
			if (config('danx.audit.debug')) {
				static::logDebug("Failed to create audit entry: " . $e->getMessage());
			}

			return null;
		}
	}

	/**
	 * Stop attributing anything to the current audit request: the next log line, API log, error
	 * or audit creates (or is handed) an audit request of its own.
	 *
	 * The current audit request is a static, so in a process that outlives one unit of work — a
	 * queue worker, a warm Vapor queue Lambda — it outlives that unit too. Called at the boundaries
	 * of every job a queue worker runs ({@see \Newms87\Danx\Listeners\ReleaseAuditRequestAtJobBoundary})
	 * and before a danx Job binds its own ({@see Job::__unserialize()}).
	 */
	public static function releaseAuditRequest(): void
	{
		self::$auditRequest = null;
	}

	/**
	 * THE way to delete `audit_request` rows. Deletes every row $scope selects, then releases
	 * {@see $auditRequest} if the row it names is gone.
	 *
	 * ## Why every deleter must come through here (SG-858)
	 *
	 * The database handles everything that points AT an audit request: `audits`, `api_logs`,
	 * `error_log_entry` rows and child audit requests are cascade-deleted with it, and
	 * `job_dispatch`'s two audit request columns are nulled (migration
	 * `0019_danx_audit_request_foreign_keys`). The one reference the database cannot reach is
	 * this process's own memory: {@see $auditRequest} is a process-lifetime static, and after a
	 * delete it would still name the deleted row. With the foreign keys in place, the next audit
	 * write in this process would then fail.
	 *
	 * So the check runs once, after the delete, rather than on every read of the static (see
	 * {@see auditRequestExists()} for what a per-read check would cost). It looks the row up
	 * rather than asking whether $scope selected it, because the cascade also removes rows $scope
	 * did not name: every child audit request of a deleted one.
	 *
	 * This reaches only THIS process. Another process whose current audit request is deleted
	 * under it has its next audit write rejected, so callers must not delete audit requests that
	 * running work is still using: a queue job and a web request each release theirs at their
	 * own start, and a caller deleting the trail of work that may still be running must first
	 * refuse while it is.
	 *
	 * A test in the consuming application fails if any code deletes `audit_request` rows by any
	 * other route.
	 *
	 * @param Closure(Builder): Builder $scope narrows `AuditRequest::query()` to the rows to delete
	 * @return int the number of `audit_request` rows deleted
	 */
	public static function deleteAuditRequests(Closure $scope): int
	{
		$deleted = $scope(AuditRequest::query())->delete();

		if (self::$auditRequest && !self::auditRequestExists(self::$auditRequest->id)) {
			self::releaseAuditRequest();
		}

		return $deleted;
	}

	/**
	 * Create a child audit request parented to the given audit request ID.
	 * Used by ProcessFork to give each forked child its own isolated audit context.
	 *
	 * Sets AuditDriver::$auditRequest to the new child, so all subsequent logs,
	 * API logs, and errors in this process are attributed to the child.
	 *
	 * $parentId is checked with {@see auditRequestExists()} before being written (SG-859), and
	 * this is the one place that check is still needed. The caller (ProcessFork) captured
	 * $parentId in the PARENT process before forking, so it is a plain integer in this child's
	 * memory: the database cannot null it and {@see deleteAuditRequests()} running in another
	 * process cannot release it. If the row it names is gone by the time this INSERT runs,
	 * writing it anyway violates `audit_request_parent_id_foreign` and the whole child creation
	 * fails, instead of degrading to a parentless audit request.
	 */
	public static function createChildAuditRequest(int $parentId, string $url): ?AuditRequest
	{
		if (!config('danx.audit.enabled')) {
			return null;
		}

		try {
			self::$auditRequest = AuditRequest::create([
				'parent_id'   => self::auditRequestExists($parentId) ? $parentId : null,
				'session_id'  => self::getSessionUuid(),
				'user_id'     => user()?->id,
				'team_id'     => team()?->id,
				'environment' => app()->environment(),
				'url'         => substr($url, 0, 512),
				'request'     => [],
				'time'        => 0,
			]);

			return self::$auditRequest;
		} catch (Exception $e) {
			if (config('danx.audit.debug')) {
				static::logDebug("Failed to create child audit request: " . $e->getMessage());
			}

			return null;
		}
	}

	/**
	 * Whether an `audit_request` row with this id currently exists.
	 *
	 * ## Where it is used, and why it is NOT in {@see getAuditRequest()} (SG-859, SG-858)
	 *
	 * Two callers, each checking an id the database cannot keep current for it:
	 *
	 * - {@see deleteAuditRequests()} — after a delete, whether {@see $auditRequest} still names a
	 *   live row. Once per delete call.
	 * - {@see createChildAuditRequest()} — whether the parent id a forked child was handed, as a
	 *   plain integer across the fork, still exists. Once per forked child.
	 *
	 * Every stored reference is the database's job (migration
	 * `0019_danx_audit_request_foreign_keys` cascades or nulls each one), so no read of a stored
	 * id needs this check. {@see \Newms87\Danx\Jobs\Job::__unserialize()} used to, before
	 * copying `job_dispatch.dispatch_audit_request_id` into a `parent_id`; it reads that column
	 * from a row it has just loaded, and the key now nulls it the moment its audit request is
	 * deleted.
	 *
	 * It is deliberately NOT added to {@see getAuditRequest()}'s read path. That path is called
	 * on every audit log line ({@see \Newms87\Danx\Logging\Audit\AuditLogHandler}), every
	 * `ApiLog`/`ErrorLog` create, every `ModelSavedEvent`, and every command-execution log — the
	 * single hottest path in the whole audited-write surface. Once `self::$auditRequest` is set,
	 * every one of those calls today costs zero queries; adding an existence check there would
	 * add one query to EVERY one of them, for the rest of the process, to guard against a hazard
	 * that {@see deleteAuditRequests()} already clears at the only moment it can arise.
	 *
	 * Measured (SG-859), not assumed: `AuditRequest::whereKey()->exists()` against a warm
	 * Postgres connection in this repo's Sail container costs ~0.24ms/call (500-call average),
	 * against ~0.002ms for the cached `getAuditRequest()` read it would otherwise replace — the
	 * existence check is ~100x more expensive than the read it would be added to. Production's
	 * own `audit_request.log_line_count` (7-day sample, 138k rows, 2026-09-22) puts the real
	 * multiplier in context: every one of those log lines is one `getAuditRequest()` call via
	 * `AuditLogHandler`, averaging ~28 per audit_request and reaching 120,070 on the single
	 * busiest observed row (an `ArrayIdentityResolution` ProcessFork batch). Adding the check to
	 * `getAuditRequest()` would have added roughly 120,070 × 0.24ms ≈ 29 seconds of pure query
	 * latency to that ONE fork's audit trail alone. Checking only at the two callers above costs
	 * the same ~0.24ms once per delete and once per forked child — negligible where
	 * `getAuditRequest()`'s cost is not.
	 */
	public static function auditRequestExists(int $id): bool
	{
		return AuditRequest::whereKey($id)->exists();
	}

	/**
	 * Creates the Audit Request object only once per client request and returns the original request
	 * object on subsequent calls
	 *
	 * @return AuditRequest|Model
	 */
	public static function getAuditRequest($url = null): ?AuditRequest
	{
		// If auditing is disabled, do nothing
		if (!config('danx.audit.enabled')) {
			return null;
		}

		if (!self::$auditRequest) {
			try {
				$url = Job::$runningJob?->ref ?: substr($url ?: self::url(), 0, 512);

				self::$auditRequest = AuditRequest::create([
					'session_id'  => self::getSessionUuid(),
					'user_id'     => user()?->id,
					'team_id'     => team()?->id,
					'environment' => app()->environment(),
					'url'         => $url,
					'request'     => self::getRequest(),
					'time'        => 0,
				]);
			} catch(Exception $e) {
				if (config('danx.audit.debug')) {
					static::logDebug("Failed to create audit request. Auditing has been disabled.\n\n" . $e->getMessage());
				}
				config()->set('danx.audit.enabled', false);

				return null;
			}
		}

		return self::$auditRequest;
	}

	/**
	 * Attempts to retrieve the UUID for the session via the header request or cookies
	 *
	 * @return string
	 */
	public static function getSessionUuid()
	{
		$userAgent = self::userAgent();

		// Check if there is a UUID specific for this User Agent (ie: kube-probe, or specific crawlers) to avoid creating a new session every time
		$uuid = @self::IGNORE_USER_AGENTS[$userAgent];

		if (!$uuid) {
			$request = request();

			$uuid = $request->header(self::SESSION_HEADER);

			if (!$uuid || strlen($uuid) !== 36) {
				$uuid = $request->cookie(self::SESSION_COOKIE);

				if (!$uuid) {
					$uuid = @$_COOKIE[self::SESSION_COOKIE];
				}
			}
		}

		return $uuid ?: 'no-session-id';
	}

	/**
	 * The Client User Agent resolver
	 *
	 * @return null|string
	 */
	public static function userAgent()
	{
		return request()->header('User-Agent');
	}

	/**
	 * The User Identifying browser fingerprint
	 *
	 * @return array|string
	 */
	public static function fingerprint()
	{
		return request()->cookie(self::FINGERPRINT_COOKIE);
	}

	/**
	 * The client IP Address resolver
	 *
	 * @return string
	 */
	public static function ipAddress()
	{
		return request()->ip();
	}

	/**
	 * The Request URL resolver
	 *
	 * @return string
	 */
	public static function url()
	{
		return request()->fullUrl();
	}

	/**
	 * Returns relevant request data
	 *
	 * @return array
	 */
	public static function getRequest()
	{
		$request = request();

		$data = $request->all();

		// Hide passwords, etc.
		self::hideSensitiveData($data);

		return [
			'method'  => $request->method(),
			'headers' => $request->header(),
			'cookies' => $request->cookie(),
			'data'    => $data,
		];
	}

	/**
	 * Screens for sensitive information like passwords and set the value of that data to '*****'
	 */
	public static function hideSensitiveData(&$data)
	{
		foreach($data as $key => &$value) {
			foreach(self::SENSITIVE_FIELDS as $regex) {
				if (preg_match($regex, $key)) {
					$value = '*****';
				}
			}
		}
	}

	/**
	 * Intelligently filter value changes that should not be recorded in the database
	 * Looking for things like boolean mismatch (1 == false) or sensitive information
	 *
	 * @param            $data
	 * @param Model|null $model
	 * @return object
	 */
	public static function cleanValues($data, Model $model = null)
	{
		$oldValues = [];
		$newValues = [];

		foreach($data->new_values as $key => $newValue) {
			if (is_array($data->old_values) && array_key_exists($key, $data->old_values)) {
				$oldValue = $data->old_values[$key];

				// Attempt to cast the new value to the same type as the old value
				if (gettype($newValue) !== gettype($oldValue)) {
					if (is_string($oldValue)) {
						$newValue = (string)$newValue;
					} elseif (is_integer($oldValue)) {
						$newValue = (int)$newValue;
					} elseif (is_float($oldValue)) {
						$newValue = (float)$newValue;
					} elseif (is_bool($oldValue)) {
						$newValue = (bool)$newValue;
					}
				}

				// NOTE: it's possible that the values are supposed to be non-numeric strings, but just happen to be numeric.
				//       In this case, only compare as numbers if they both are numeric.
				//       Technically this is ambiguous: "1.00" == "1" is different in a numeric context, but in a string context they are different
				//       Weather it makes sense to record this change depends, but most cases probably better to ignore
				if (is_numeric($oldValue) && is_numeric($newValue)) {
					if ($oldValue == $newValue) {
						continue;
					} else {
						// In the case it is numeric and has decimal places. We want to make sure we're comparing the correct number of decimal places
						$tableName = $model?->getTable();
						if ($tableName) {
							$decimalPlaces = cache()->rememberForever($tableName . '.' . $key,
								function () use ($tableName, $key) {
									if (preg_match("#decimal\\(\\d+,(\\d+)\\)#", Schema::getColumnType($tableName, $key, true), $matches)) {
										return $matches[1] ?? 0;
									}

									return null;
								});

							if ($decimalPlaces) {
								$oldValue = round($oldValue, $decimalPlaces);
								$newValue = round($newValue, $decimalPlaces);

								if ($oldValue === $newValue) {
									continue;
								}
							}
						}
					}
				}

				// Sanity check to make sure we are only recording changed values
				if ($oldValue === $newValue) {
					continue;
				}

				// Allow this old value to be recorded
				$oldValues[$key] = $oldValue;
			}

			// Allow this new value to be recorded
			$newValues[$key] = $newValue;
		}

		// Hide passwords, etc.
		self::hideSensitiveData($oldValues);
		self::hideSensitiveData($newValues);

		return (object)[
			'old_values' => $oldValues,
			'new_values' => $newValues,
		];
	}

	/**
	 * Remove older audits that go over the threshold.
	 *
	 * @param Auditable $model
	 * @return bool
	 */
	public function prune(Auditable $model): bool
	{
		if (($threshold = $model->getAuditThreshold()) > 0) {
			$forRemoval = $model->audits()
				->latest()
				->get()
				->slice($threshold)
				->pluck('id');

			if (!$forRemoval->isEmpty()) {
				return $model->audits()
						->whereIn('id', $forRemoval)
						->delete() > 0;
			}
		}

		return false;
	}
}

<?php

namespace Tests\Unit\Logging;

use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Newms87\Danx\Logging\Audit\AuditLogLogger;
use Newms87\Danx\Models\Audit\ApiLog;
use Newms87\Danx\Models\Audit\ErrorLog;
use Newms87\Danx\Models\Audit\ErrorLogEntry;
use Newms87\Danx\Traits\HasDebugLogging;
use Orchestra\Testbench\TestCase;

// ErrorLog::addEntry() calls the global user() helper that the consuming app normally defines.
require_once __DIR__ . '/../Api/support/helpers.php';

/**
 * An exception that declares its own logging level, the way ErrorLog::logException()
 * lets a Throwable override the level it was logged at.
 */
class CriticalLevelException extends Exception
{
    public static int $level = ErrorLog::CRITICAL;
}

/**
 * A class logging through HasDebugLogging, the way every service in a consuming app does.
 */
class LogsThroughDebugLoggingTrait
{
    use HasDebugLogging;
}

/**
 * AuditLogHandler records an error-level log line as an ErrorLog + ErrorLogEntry, whether
 * or not the line carries an exception.
 *
 * Regression guard for the message-only path: the handler used to decide "is this an
 * error?" by comparing an int against a Monolog\Level enum. PHP evaluates `int >= enum`
 * as false for every value, so a Log::error('...') with no exception never produced a row
 * — only exceptions did.
 *
 * Runs against in-memory sqlite, the same isolation ApiHedgingTest uses: every test method
 * gets a brand-new empty database, so there is nothing to clean up.
 */
class AuditLogHandlerErrorRecordingTest extends TestCase
{
    private Logger $logger;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // No audit request: this suite is about the error_logs rows, not the audit_request log text.
        $app['config']->set('danx.audit.enabled', false);

        // The channel exactly as a consuming app declares it (gpt-manager's config/logging.php):
        // the handler takes every level from debug up.
        $app['config']->set('logging.channels.auditlog', [
            'driver' => 'custom',
            'via'    => AuditLogLogger::class,
            'level'  => 'debug',
        ]);
        $app['config']->set('logging.default', 'auditlog');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The two tables the handler writes, with the columns ErrorLog / ErrorLogEntry fill.
        Schema::create('error_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('root_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('hash')->unique();
            $table->string('error_class');
            $table->string('code');
            $table->string('level');
            $table->string('message', 512);
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('count');
            $table->dateTime('last_seen_at');
            $table->dateTime('last_notified_at')->nullable();
            $table->boolean('send_notifications')->default(true);
            $table->json('stack_trace')->nullable();
            $table->timestamps();
        });

        Schema::create('error_log_entry', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('error_log_id');
            $table->unsignedInteger('audit_request_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('message', 512)->default('');
            $table->longText('full_message')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_retryable')->default(false);
            $table->timestamps();
        });

        $this->logger = (new AuditLogLogger)(['level' => 'debug']);
    }

    public function test_message_only_error_creates_error_log_and_entry(): void
    {
        // When
        $this->logger->error('Settlement could not find the grading run');

        // Then
        $errorLog = ErrorLog::where('error_class', 'Message')->sole();
        $this->assertEquals(ErrorLog::ERROR, (int)$errorLog->level);
        $this->assertEquals('Settlement could not find the grading run', $errorLog->message);
        $this->assertEquals(1, ErrorLogEntry::where('error_log_id', $errorLog->id)->count());
    }

    public function test_message_only_levels_above_error_record_their_own_level(): void
    {
        // When
        $this->logger->critical('Critical message');
        $this->logger->alert('Alert message');
        $this->logger->emergency('Emergency message');

        // Then
        $levels = ErrorLog::where('error_class', 'Message')->orderBy('id')->pluck('level')->map(fn($level) => (int)$level)->all();
        $this->assertEquals([ErrorLog::CRITICAL, ErrorLog::ALERT, ErrorLog::EMERGENCY], $levels);
    }

    public function test_message_only_lines_below_error_create_no_error_log(): void
    {
        // When - every level under the ERROR threshold
        $this->logger->debug('Debug message');
        $this->logger->info('Info message');
        $this->logger->notice('Notice message');
        $this->logger->warning('Warning message');

        // Then
        $this->assertEquals(0, ErrorLog::count());
        $this->assertEquals(0, ErrorLogEntry::count());
    }

    public function test_has_debug_logging_log_error_is_recorded(): void
    {
        // When - the logError() style every consuming service uses, through the default channel
        LogsThroughDebugLoggingTrait::logError('Directive has no schema');

        // Then
        $errorLog = ErrorLog::where('error_class', 'Message')->sole();
        $this->assertEquals('[LogsThroughDebugLoggingTrait] Directive has no schema', $errorLog->message);
        $this->assertEquals(ErrorLog::ERROR, (int)$errorLog->level);
    }

    public function test_logged_exception_is_still_recorded_under_its_class(): void
    {
        // When
        $this->logger->error('Wrapped', ['exception' => new Exception('The real failure')]);

        // Then - one row, under the exception's class, never a duplicate Message row
        $errorLog = ErrorLog::sole();
        $this->assertEquals(Exception::class, $errorLog->error_class);
        $this->assertEquals('The real failure', $errorLog->message);
        $this->assertEquals(ErrorLog::ERROR, (int)$errorLog->level);
    }

    public function test_exception_declaring_its_own_level_is_recorded_at_that_level_with_its_chain(): void
    {
        // Given - an exception with a declared level and a previous exception in its chain
        $exception = new CriticalLevelException('Outer', 0, new Exception('Inner'));

        // When
        ErrorLog::logException(ErrorLog::ERROR, $exception);

        // Then - both links are recorded, each at the declared integer level
        $outer = ErrorLog::where('error_class', CriticalLevelException::class)->sole();
        $inner = ErrorLog::where('parent_id', $outer->id)->sole();
        $this->assertEquals(ErrorLog::CRITICAL, (int)$outer->level);
        $this->assertEquals(Exception::class, $inner->error_class);
        $this->assertEquals(ErrorLog::CRITICAL, (int)$inner->level);
    }

    public function test_directly_logged_exception_is_recorded_once(): void
    {
        // When - the direct call AuditingMiddleware and ActionController make
        ErrorLog::logException(ErrorLog::ERROR, new Exception('Request blew up'));

        // Then - seen once, one entry. logException() used to re-log the exception through the
        // log channel, and AuditLogHandler recorded it a second time: count 2, two entries.
        $errorLog = ErrorLog::sole();
        $this->assertEquals(1, (int)$errorLog->count);
        $this->assertEquals(1, ErrorLogEntry::where('error_log_id', $errorLog->id)->count());
    }

    public function test_directly_logged_exception_chain_records_each_link_once_and_no_orphan(): void
    {
        // Given - a chain whose outer link declares a level other than the channel's ERROR
        $exception = new CriticalLevelException('Outer', 0, new Exception('Inner'));

        // When
        ErrorLog::logException(ErrorLog::ERROR, $exception);

        // Then - exactly the two links, the inner one parented. The channel re-log used to
        // record the inner exception again, parentless, at ERROR: a third, orphan row.
        $this->assertEquals(2, ErrorLog::count());
        $outer = ErrorLog::where('error_class', CriticalLevelException::class)->sole();
        $inner = ErrorLog::where('error_class', Exception::class)->sole();
        $this->assertEquals($outer->id, $inner->parent_id);
        $this->assertEquals([1, 1], [(int)$outer->count, (int)$inner->count]);
        $this->assertEquals(2, ErrorLogEntry::count());
    }

    public function test_directly_logged_exception_still_writes_its_log_line(): void
    {
        // Given - a handler that captures what reaches the channel
        $captured = new TestHandler();
        Log::channel('auditlog')->getLogger()->pushHandler($captured);

        // When
        ErrorLog::logException(ErrorLog::ERROR, new Exception('Request blew up'));

        // Then - the line a request's log shows is still written, once
        $this->assertCount(1, $captured->getRecords());
        $this->assertTrue($captured->hasErrorThatContains('Request blew up'));
    }

    public function test_message_errors_differing_only_in_ids_names_and_numbers_group_into_one_error_log(): void
    {
        // Given - production message shapes, each pair differing only in its variable parts
        $pairs = [
            'quoted name' => [
                "[ArrayIdentityProcessor] Extracted Professional 'Edgar' belongs to none of the parent records this extraction offered, so it is persisted with no parent and flagged.",
                "[ArrayIdentityProcessor] Extracted Professional 'Dr. Jane O'Brien' belongs to none of the parent records this extraction offered, so it is persisted with no parent and flagged.",
            ],
            'model toString' => [
                "[ApiLog] Failed <ApiLog id='19149' GET 401 https://api.github.com/repos/newms87/gpt-manager/commits?since=2026-08-05>: Client error: `GET https://api.github.com/repos/newms87/gpt-manager/commits?since=2026-08-05` resulted in a `401 Unauthorized` response",
                "[ApiLog] Failed <ApiLog id='22738' GET 401 https://api.github.com/repos/newms87/gpt-manager/commits?since=2026-08-06>: Client error: `GET https://api.github.com/repos/newms87/gpt-manager/commits?since=2026-08-06` resulted in a `401 Unauthorized` response",
            ],
            'hash id' => [
                '[ExtractIdentityTaskWorkerDefinition] Identity extraction artifact: TeamObject #1762 no longer exists',
                '[ExtractIdentityTaskWorkerDefinition] Identity extraction artifact: TeamObject #1555 no longer exists',
            ],
            'bare numbers before any colon' => [
                'Failed to mark JobDispatch 123 as timed out after 30s',
                'Failed to mark JobDispatch 98765 as timed out after 600.5s',
            ],
            'uuid' => [
                'Sandbox 9fcbb2d0-30aa-4e23-a9a1-8847f00fc493 could not be provisioned',
                'Sandbox 0b4e7a31-5c2d-4f8e-9a6b-1d3c5e7f9a2b could not be provisioned',
            ],
            'apostrophes inside words are not quotes' => [
                "The model's answer for 'Edgar' can't be read",
                "The model's answer for 'Bob' can't be read",
            ],
        ];

        // When
        foreach($pairs as [$first, $second]) {
            $this->logger->error($first);
            $this->logger->error($second);
        }

        // Then - one row per pair, seen twice. SG-809: the displayed message now reflects
        // the SECOND (latest) occurrence, not frozen on the first — see
        // test_a_grouped_entrys_message_reflects_the_latest_occurrence_not_the_first() for
        // the dedicated regression test of that half of the fix.
        $this->assertEquals(count($pairs), ErrorLog::count());

        foreach(array_values($pairs) as $index => [, $second]) {
            $errorLog = ErrorLog::orderBy('id')->skip($index)->first();
            $this->assertEquals(substr($second, 0, ErrorLog::MAX_MESSAGE_SIZE), $errorLog->message);
            $this->assertEquals(2, (int)$errorLog->count, $errorLog->message);
            $this->assertEquals(2, ErrorLogEntry::where('error_log_id', $errorLog->id)->count());
        }
    }

    public function test_messages_sharing_a_prefix_but_differing_after_the_first_colon_no_longer_collapse(): void
    {
        // Given - two genuinely different occurrences sharing the exact text up to the first
        // colon (SG-809's real production shape: LockHelper's
        // "🔴🔒 RELEASE-BY-OWNER-FAILED: <key>"). The old grouping key was only the text up to
        // the first colon, which discarded everything after it — the ONLY part that told
        // these two apart — and merged them into one row. Measured in production: one such
        // row had merged 186 distinct real keys, spanning two unrelated task-worker workflows
        // and an unrelated schema-definition publish job, under a single hash.
        $this->logger->error('🔴🔒 RELEASE-BY-OWNER-FAILED: task-worker:workflow-56:6aab5eb99f3431.60360670 — not held by the given owner (expired and re-acquired by someone else, or never held)');
        $this->logger->error('🔴🔒 RELEASE-BY-OWNER-FAILED: recompute-publish-content-hash:App\Models\Schema\SchemaDefinition:135 — not held by the given owner (expired and re-acquired by someone else, or never held)');

        // Then - two distinct entries, not one merged row silently absorbing an unrelated key
        $this->assertEquals(2, ErrorLog::count());
    }

    public function test_a_grouped_entrys_message_reflects_the_latest_occurrence_not_the_first(): void
    {
        // Given - two occurrences that legitimately group (they differ only in a normalized
        // quoted value), logged in order
        $this->logger->error("[ArrayIdentityProcessor] Extracted Professional 'Edgar' belongs to none of the parent records this extraction offered, so it is persisted with no parent and flagged.");
        $this->logger->error("[ArrayIdentityProcessor] Extracted Professional 'Dr. Jane O'Brien' belongs to none of the parent records this extraction offered, so it is persisted with no parent and flagged.");

        // Then - one row, but SG-809: its displayed message is the SECOND (latest)
        // occurrence, not frozen on whichever happened first. Every individual occurrence is
        // still preserved in full on its own ErrorLogEntry regardless.
        $errorLog = ErrorLog::sole();
        $this->assertEquals(2, (int)$errorLog->count);
        $this->assertStringContainsString('Dr. Jane', $errorLog->message);
        $this->assertStringNotContainsString("'Edgar'", $errorLog->message);
        $this->assertEquals(2, ErrorLogEntry::where('error_log_id', $errorLog->id)->count());
    }

    public function test_a_failed_api_attempt_logs_a_warning_and_records_no_error(): void
    {
        // Given - a handler that captures what reaches the channel, and one failed attempt
        $captured = new TestHandler();
        Log::channel('auditlog')->getLogger()->pushHandler($captured);
        $failure = RequestException::create(new Request('GET', 'https://api.example.invalid/things'), new Response(401));

        // When - the per-attempt hook Api calls; the call as a whole throws separately
        ApiLog::logResponseError(new ApiLog(['method' => 'GET', 'url' => 'https://api.example.invalid/things']), $failure);

        // Then
        $this->assertTrue($captured->hasWarningThatContains('Failed <ApiLog'));
        $this->assertEquals(0, ErrorLog::count());
    }

    public function test_message_errors_that_say_different_things_stay_apart(): void
    {
        // When - different wording, and the same wording about different (unquoted) object types
        $this->logger->error('Directive has no schema');
        $this->logger->error('Schema has no directive');
        $this->logger->error("Extracted Vehicle 'A' belongs to none of the parent records");
        $this->logger->error("Extracted Professional 'A' belongs to none of the parent records");

        // Then
        $this->assertEquals(4, ErrorLog::count());
    }

    public function test_level_enum_is_normalized_to_its_integer_value(): void
    {
        $this->assertSame(ErrorLog::ERROR, ErrorLog::normalizeLevel(Level::Error));
        $this->assertSame(ErrorLog::CRITICAL, ErrorLog::normalizeLevel(ErrorLog::CRITICAL));
        $this->assertSame(ErrorLog::WARNING, ErrorLog::normalizeLevel('warning'));
        $this->assertSame(ErrorLog::ALERT, ErrorLog::normalizeLevel('ALERT'));
        $this->assertSame(ErrorLog::NOTICE, ErrorLog::normalizeLevel('250'));
    }
}

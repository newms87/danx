<?php

namespace Tests\Unit\Logging;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monolog\Level;
use Monolog\Logger;
use Newms87\Danx\Logging\Audit\AuditLogLogger;
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

        // Then - both links are recorded, each at the declared integer level. (The chained
        // row is located by its parent: logException() also re-logs each link through the
        // log channel, which records the inner exception a second time, parentless, at the
        // channel's level — pre-existing behaviour this test does not pin.)
        $outer = ErrorLog::where('error_class', CriticalLevelException::class)->sole();
        $inner = ErrorLog::where('parent_id', $outer->id)->sole();
        $this->assertEquals(ErrorLog::CRITICAL, (int)$outer->level);
        $this->assertEquals(Exception::class, $inner->error_class);
        $this->assertEquals(ErrorLog::CRITICAL, (int)$inner->level);
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

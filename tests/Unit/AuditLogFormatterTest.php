<?php

namespace Tests\Unit;

use Exception;
use Monolog\LogRecord;
use Newms87\Danx\Logging\Audit\AuditLogFormatter;
use PHPUnit\Framework\TestCase;

class AuditLogFormatterTest extends TestCase
{
    /**
     * Test that Throwable exceptions are returned in the formatted exception field.
     */
    public function test_format_returns_throwable_exception(): void
    {
        $formatter = new AuditLogFormatter();
        $exception = new Exception('Test exception message');

        $record = [
            'message' => 'Log message',
            'context' => ['exception' => $exception],
        ];

        $formatted = $formatter->format($record);

        $this->assertSame($exception, $formatted['exception']);
        $this->assertStringContainsString('Test exception message', $formatted['message']);
    }

    /**
     * Test that string exceptions return null for exception field.
     * This prevents TypeError when AuditLogHandler passes to ErrorLog::logException().
     */
    public function test_format_returns_null_for_string_exception(): void
    {
        $formatter = new AuditLogFormatter();
        $stringException = 'This is a string, not a Throwable';

        $record = [
            'message' => 'Log message',
            'context' => ['exception' => $stringException],
        ];

        $formatted = $formatter->format($record);

        // Exception field should be null when the exception is a string
        $this->assertNull($formatted['exception']);
        // Message should contain the string exception content
        $this->assertEquals($stringException, $formatted['message']);
    }

    /**
     * Test that records without exceptions work correctly.
     */
    public function test_format_handles_no_exception(): void
    {
        $formatter = new AuditLogFormatter();

        $record = [
            'message' => 'Regular log message',
            'context' => [],
        ];

        $formatted = $formatter->format($record);

        $this->assertNull($formatted['exception']);
        $this->assertEquals('Regular log message', $formatted['message']);
    }
}

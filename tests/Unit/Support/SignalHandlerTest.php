<?php

namespace Tests\Unit\Support;

use Newms87\Danx\Support\SignalHandler;
use PHPUnit\Framework\TestCase;

class SignalHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static state after each test
        SignalHandler::$currentOperation        = null;
        SignalHandler::$currentOperationStarted = null;

        parent::tearDown();
    }

    /**
     * Test that setCurrentOperation() sets the operation name and timestamp.
     */
    public function test_set_current_operation_sets_properties(): void
    {
        SignalHandler::setCurrentOperation('test-operation');

        $this->assertSame('test-operation', SignalHandler::$currentOperation);
        $this->assertNotNull(SignalHandler::$currentOperationStarted);
    }

    /**
     * Test that setCurrentOperation(null) clears both properties.
     */
    public function test_set_current_operation_clears_properties_when_null(): void
    {
        // First set an operation
        SignalHandler::setCurrentOperation('some-operation');
        $this->assertNotNull(SignalHandler::$currentOperation);
        $this->assertNotNull(SignalHandler::$currentOperationStarted);

        // Now clear it
        SignalHandler::setCurrentOperation(null);

        $this->assertNull(SignalHandler::$currentOperation);
        $this->assertNull(SignalHandler::$currentOperationStarted);
    }

    /**
     * Test that currentOperationStarted is a valid timestamp when operation is set.
     */
    public function test_current_operation_started_is_timestamp_when_set(): void
    {
        $beforeTime = time();

        SignalHandler::setCurrentOperation('timestamp-test');

        $afterTime = time();

        $this->assertIsInt(SignalHandler::$currentOperationStarted);

        // Timestamp should be between before and after time (inclusive)
        $this->assertGreaterThanOrEqual($beforeTime, SignalHandler::$currentOperationStarted);
        $this->assertLessThanOrEqual($afterTime, SignalHandler::$currentOperationStarted);
    }
}

<?php

namespace Tests\Feature\Audit;

use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Models\Audit\AuditRequest;
use Tests\TestCase;

/**
 * Tests for AuditDriver::createChildAuditRequest().
 *
 * Verifies that child audit requests are created with correct parent linkage,
 * session isolation, and that the static AuditDriver context is updated.
 */
class AuditDriverTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config()->set('danx.audit.enabled', true);
    }

    protected function tearDown(): void
    {
        // Reset the static audit request to avoid polluting other tests
        AuditDriver::$auditRequest = null;
        parent::tearDown();
    }

    public function test_create_child_audit_request_sets_parent_id_and_url(): void
    {
        // Given - a parent audit request
        $parent = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/parent-request',
        ]);

        // When
        $child = AuditDriver::createChildAuditRequest($parent->id, 'ProcessFork:batch-3');

        // Then
        $this->assertNotNull($child);
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals('ProcessFork:batch-3', $child->url);
        $this->assertEquals('testing', $child->environment);
    }

    public function test_create_child_audit_request_updates_static_context(): void
    {
        // Given - a parent audit request
        $parent = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/parent-request',
        ]);

        // When
        $child = AuditDriver::createChildAuditRequest($parent->id, 'ProcessFork:batch-0');

        // Then - the static $auditRequest should now point to the child
        $this->assertNotNull(AuditDriver::$auditRequest);
        $this->assertEquals($child->id, AuditDriver::$auditRequest->id);
    }

    public function test_create_child_audit_request_creates_children_on_parent(): void
    {
        // Given - a parent audit request with no children
        $parent = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/parent-request',
        ]);

        $this->assertEquals(0, $parent->children()->count());

        // When - create two children
        AuditDriver::createChildAuditRequest($parent->id, 'ProcessFork:batch-0');
        AuditDriver::createChildAuditRequest($parent->id, 'ProcessFork:batch-1');

        // Then - parent should have two children via the relationship
        $this->assertEquals(2, $parent->children()->count());
    }
}

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

    /**
     * SG-859 — the crash this guards against: ProcessFork captures $parentAuditRequestId in the
     * parent process before forking; if the row it names is deleted (a workspace clean, a team
     * purge, a test transaction rollback — anything) before the forked child reaches this INSERT,
     * writing that dead id into `parent_id` violates `audit_request_parent_id_foreign` and the
     * whole child audit request fails to create. auditRequestExists() is checked first so a dead
     * parent degrades to no parent instead of crashing.
     */
    public function test_create_child_audit_request_with_a_nonexistent_parent_id_does_not_crash(): void
    {
        // Given - a parent id that does not, and never did, exist in this test's transaction
        $deadParentId = 999999999;
        $this->assertDatabaseMissing('audit_request', ['id' => $deadParentId]);

        // When
        $child = AuditDriver::createChildAuditRequest($deadParentId, 'ProcessFork:orphaned');

        // Then - the child is still created, just without the dead parent link
        $this->assertNotNull($child, 'a dead parent id must not prevent the child from being created');
        $this->assertNull($child->parent_id, 'a dead parent id must not be written to parent_id');
    }
}

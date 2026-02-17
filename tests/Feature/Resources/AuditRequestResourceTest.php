<?php

namespace Tests\Feature\Resources;

use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Resources\Audit\AuditRequestResource;
use Tests\TestCase;

/**
 * Tests for AuditRequestResource::resolveAncestorIds().
 *
 * Verifies the ancestor chain traversal via direct parent_id links
 * and the legacy JobDispatch fallback for older audit requests.
 */
class AuditRequestResourceTest extends TestCase
{
    public function test_resolve_ancestor_ids_via_parent_id_chain(): void
    {
        // Given - a 3-level hierarchy: grandparent → parent → child
        $grandparent = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/grandparent',
        ]);

        $parent = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/parent',
            'parent_id'   => $grandparent->id,
        ]);

        $child = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/child',
            'parent_id'   => $parent->id,
        ]);

        // When
        $ancestorIds = AuditRequestResource::resolveAncestorIds($child);

        // Then - ordered root to current (grandparent, parent, child)
        $this->assertCount(3, $ancestorIds);
        $this->assertEquals($grandparent->id, $ancestorIds[0]);
        $this->assertEquals($parent->id, $ancestorIds[1]);
        $this->assertEquals($child->id, $ancestorIds[2]);
    }

    public function test_resolve_ancestor_ids_falls_back_to_legacy_job_dispatch_chain(): void
    {
        // Given - audit requests linked via JobDispatch (no parent_id)
        $dispatcher = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/dispatcher',
        ]);

        $runner = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/runner',
        ]);

        // Link via JobDispatch: runner ran a job dispatched by dispatcher
        JobDispatch::create([
            'ref'                         => 'test-job',
            'name'                        => 'TestJob',
            'status'                      => JobDispatch::STATUS_COMPLETE,
            'running_audit_request_id'    => $runner->id,
            'dispatch_audit_request_id'   => $dispatcher->id,
        ]);

        // When
        $ancestorIds = AuditRequestResource::resolveAncestorIds($runner);

        // Then - should trace through the JobDispatch chain
        $this->assertCount(2, $ancestorIds);
        $this->assertEquals($dispatcher->id, $ancestorIds[0]);
        $this->assertEquals($runner->id, $ancestorIds[1]);
    }

    public function test_resolve_ancestor_ids_returns_single_id_for_root_request(): void
    {
        // Given - a root audit request with no parent and no ran jobs
        $root = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/root',
        ]);

        // When
        $ancestorIds = AuditRequestResource::resolveAncestorIds($root);

        // Then - just the root itself
        $this->assertCount(1, $ancestorIds);
        $this->assertEquals($root->id, $ancestorIds[0]);
    }

    public function test_resolve_ancestor_ids_respects_depth_limit(): void
    {
        // Given - a chain deeper than the 20-level limit
        $requests = [];
        $previous = null;

        for ($i = 0; $i < 25; $i++) {
            $request = AuditRequest::create([
                'session_id'  => 'test-session',
                'environment' => 'testing',
                'url'         => "/level-$i",
                'parent_id'   => $previous?->id,
            ]);
            $requests[] = $request;
            $previous   = $request;
        }

        $deepest = end($requests);

        // When
        $ancestorIds = AuditRequestResource::resolveAncestorIds($deepest);

        // Then - should be capped at 21 (20 ancestors + the current request)
        $this->assertLessThanOrEqual(21, count($ancestorIds));
        // The deepest request should always be the last element
        $this->assertEquals($deepest->id, end($ancestorIds));
    }
}

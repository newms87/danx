<?php

namespace Tests\Feature\Resources;

use Newms87\Danx\Models\Audit\ApiLog;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Resources\Job\JobDispatchResource;
use Tests\TestCase;

/**
 * SG-485: JobDispatchResource::details() default shape.
 *
 * logs / errors / apiLogs are all closures on JobDispatchResource::data() — per
 * ActionResource::make(), a closure is included only when explicitly named. Before this
 * fix, JobDispatchResource had no details() override, so a details() call naming no
 * fields fell through to the base ActionResource::details() default of ['*' => true],
 * forcing all three on (one local record serialized to 400KB with ApiLog bodies included).
 */
class JobDispatchResourceTest extends TestCase
{
    public function test_details_with_no_fields_defaults_to_no_relations(): void
    {
        $auditRequest = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/job',
            'logs'        => 'the audit request log text',
        ]);

        ApiLog::factory()->forAuditRequest($auditRequest->id)->create();

        $jobDispatch = JobDispatch::factory()->forAuditRequest($auditRequest->id)->create();

        $response = JobDispatchResource::details($jobDispatch);

        $this->assertArrayNotHasKey('logs', $response, 'logs must not be sent unless explicitly requested');
        $this->assertArrayNotHasKey('errors', $response);
        $this->assertArrayNotHasKey('apiLogs', $response);
        // The row's own columns are still present
        $this->assertSame($jobDispatch->name, $response['name']);
    }

    public function test_details_with_explicit_fields_returns_the_named_relations(): void
    {
        $auditRequest = AuditRequest::create([
            'session_id'  => 'test-session',
            'environment' => 'testing',
            'url'         => '/job',
            'logs'        => 'the audit request log text',
        ]);

        ApiLog::factory()->forAuditRequest($auditRequest->id)->create();

        $jobDispatch = JobDispatch::factory()->forAuditRequest($auditRequest->id)->create();

        $response = JobDispatchResource::details($jobDispatch, ['logs' => true, 'apiLogs' => true]);

        $this->assertSame('the audit request log text', $response['logs']);
        $this->assertCount(1, $response['apiLogs']);
    }
}

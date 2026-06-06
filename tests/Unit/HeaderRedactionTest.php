<?php

namespace Tests\Unit;

use Newms87\Danx\Api\Api;
use Newms87\Danx\Helpers\StringHelper;
use PHPUnit\Framework\TestCase;

/**
 * Guards the credential-redaction path: API keys / bearer tokens / cookies must
 * never reach ApiLog rows or error-log request dumps in clear text.
 * (SG-342 — a live OpenAI key leaked into AuditRequest error logs.)
 */
class HeaderRedactionTest extends TestCase
{
    public function test_redactHeaders_masks_authorization_value(): void
    {
        $redacted = StringHelper::redactHeaders([
            'Authorization' => ['Bearer sk-proj-SECRET-KEY'],
            'Content-Type'  => ['application/json'],
        ]);

        $this->assertSame(['***redacted***'], $redacted['Authorization']);
        $this->assertSame(['application/json'], $redacted['Content-Type'], 'non-sensitive headers must be preserved');
    }

    public function test_redactHeaders_is_case_insensitive_and_handles_string_values(): void
    {
        $redacted = StringHelper::redactHeaders([
            'authorization'  => 'Bearer sk-proj-SECRET-KEY',
            'X-Api-Key'      => 'sk-secret',
            'Cookie'         => 'session=abc',
            'Accept'         => 'application/json',
        ]);

        $this->assertSame('***redacted***', $redacted['authorization']);
        $this->assertSame('***redacted***', $redacted['X-Api-Key']);
        $this->assertSame('***redacted***', $redacted['Cookie']);
        $this->assertSame('application/json', $redacted['Accept']);
    }

    public function test_displayHeaders_never_emits_the_raw_token(): void
    {
        $dump = Api::displayHeaders([
            'Authorization' => ['Bearer sk-proj-SECRET-KEY'],
            'Content-Type'  => ['application/json'],
        ]);

        $this->assertStringNotContainsString('sk-proj-SECRET-KEY', $dump);
        $this->assertStringContainsString('***redacted***', $dump);
        $this->assertStringContainsString('application/json', $dump);
    }
}

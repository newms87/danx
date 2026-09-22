<?php

namespace Tests\Unit\Exceptions;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Newms87\Danx\Exceptions\ApiRequestException;
use PHPUnit\Framework\TestCase;

/**
 * SG-815 (gpt-manager): {@see ApiRequestException::getUserFacingReason()} is the sentence a
 * customer-facing screen renders instead of the raw request+response dump getMessage()
 * composes. These cases are shaped from a REAL production failure
 * (gpt-manager WorkflowRun #1123, ApiLog 59155): OpenAI's own `{"error":{"message":...}}`
 * shape, a request body carrying a full extraction prompt and a model name that must never
 * reach the reason, and a connection-level failure with no response at all.
 */
class ApiRequestExceptionTest extends TestCase
{
    private function makeRequest(): Request
    {
        $body = json_encode([
            'model'        => 'gpt-5.6-luna',
            'instructions' => "## Resolved Object Tree\n\nDo NOT emit the same underlying record as two or more rows.",
        ]);

        return new Request('POST', 'https://api.openai.com/v1/responses', ['Content-Type' => 'application/json'], $body);
    }

    public function test_user_facing_reason_uses_the_openai_error_message_shape(): void
    {
        $request  = $this->makeRequest();
        $response = new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'error' => [
                'message' => "Invalid schema for response_format 'identity-extraction-response-9d4bce4': In context=('properties', 'Demand.11947', 'items', 'properties', 'phone', 'type', '0'), 'phone' is not a valid format.",
                'type'    => 'invalid_request_error',
                'param'   => 'text.format.schema',
                'code'    => 'invalid_json_schema',
            ],
        ]));

        $exception = new ApiRequestException('OpenAI', new RequestException('400 Bad Request', $request, $response));

        $this->assertSame(
            "OpenAI request failed: Invalid schema for response_format 'identity-extraction-response-9d4bce4': In context=('properties', 'Demand.11947', 'items', 'properties', 'phone', 'type', '0'), 'phone' is not a valid format.",
            $exception->getUserFacingReason(),
        );
    }

    public function test_user_facing_reason_never_carries_the_prompt_or_model_name(): void
    {
        $request  = $this->makeRequest();
        $response = new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'bad schema']]));

        $exception = new ApiRequestException('OpenAI', new RequestException('400 Bad Request', $request, $response));
        $reason    = $exception->getUserFacingReason();

        $this->assertStringNotContainsString('Resolved Object Tree', $reason);
        $this->assertStringNotContainsString('gpt-5.6-luna', $reason);
        $this->assertStringNotContainsString('Content-Type', $reason);
        $this->assertStringNotContainsString('GuzzleHttp', $reason);
    }

    /**
     * getMessage() is untouched — internal consumers (ErrorLog, AuditRequest.logs,
     * debug:replay-api-log) still get the full formatted request+response dump.
     * getUserFacingReason() is the ONLY sanitized surface.
     */
    public function test_get_message_still_carries_the_full_raw_exchange_for_internal_logs(): void
    {
        $request  = $this->makeRequest();
        $response = new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'bad schema']]));

        $exception = new ApiRequestException('OpenAI', new RequestException('400 Bad Request', $request, $response));

        $this->assertStringContainsString('gpt-5.6-luna', $exception->getMessage());
        $this->assertStringContainsString('Resolved Object Tree', $exception->getMessage());
    }

    public function test_user_facing_reason_falls_back_to_a_status_only_sentence_with_no_response(): void
    {
        $request   = $this->makeRequest();
        $exception = new ApiRequestException('OpenAI', new ConnectException('Connection refused', $request));

        $this->assertSame('OpenAI request failed.', $exception->getUserFacingReason());
    }

    public function test_get_api_name_returns_the_constructor_argument(): void
    {
        $request   = $this->makeRequest();
        $exception = new ApiRequestException('OpenAI', new ConnectException('Connection refused', $request));

        $this->assertSame('OpenAI', $exception->getApiName());
    }

    public function test_user_facing_reason_is_capped_and_whitespace_normalized(): void
    {
        $request  = $this->makeRequest();
        $longMessage = "line one\nline two\n\n" . str_repeat('x', 500);
        $response = new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => $longMessage]]));

        $exception = new ApiRequestException('OpenAI', new RequestException('400 Bad Request', $request, $response));
        $reason    = $exception->getUserFacingReason();

        $this->assertStringNotContainsString("\n", $reason);
        $this->assertLessThanOrEqual(400, mb_strlen($reason));
        $this->assertStringEndsWith('…', $reason);
    }
}

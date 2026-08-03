<?php

namespace Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Redis;
use Newms87\Danx\Api\Api;
use Newms87\Danx\Exceptions\RateLimitExceededException;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\ResponseInterface;

class RateLimitBlockTestApi extends Api
{
    public static string $serviceName = 'rate-limit-block-test';

    protected string $baseApiUrl = 'https://rate-limit-block.test/api';
}

class RateLimitParseOverrideTestApi extends RateLimitBlockTestApi
{
    public static string $serviceName = 'rate-limit-parse-override-test';

    protected function parseRateLimitBlockSeconds(ResponseInterface $response): ?int
    {
        return 3600;
    }
}

/**
 * Tests the remote provider block registry on Api (SG-40).
 *
 * When a provider returns 429, a Redis-backed block (api-remote-block:{service})
 * is registered so every other caller/fork/retry fails fast in throttle() with a
 * typed RateLimitExceededException instead of re-hitting the blocked provider.
 * Short blocks (<= rate_limit_inline_wait_max_seconds) are waited out inline to
 * preserve OpenAI-style throttle recovery; long blocks throw upward immediately.
 *
 * Redis is mocked at the facade level — no Redis server is required.
 */
class ApiRateLimitBlockTest extends TestCase
{
    private function mockClient(MockHandler $mockHandler): Client
    {
        return new Client(['handler' => HandlerStack::create($mockHandler)]);
    }

    public function test_register_service_block_and_ttl_round_trip(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->with('api-remote-block:rate-limit-block-test', 120, 1);
        Redis::shouldReceive('ttl')
            ->once()
            ->with('api-remote-block:rate-limit-block-test')
            ->andReturn(120);

        $api = new RateLimitBlockTestApi;
        $api->registerServiceBlock(120);

        $this->assertSame(120, $api->getServiceBlockTtl());
    }

    public function test_service_block_ttl_clamps_missing_key_to_zero(): void
    {
        // Redis returns -2 for a missing key and -1 for a key without TTL —
        // both must read as "not blocked"
        Redis::shouldReceive('ttl')->twice()->andReturn(-2, -1);

        $api = new RateLimitBlockTestApi;

        $this->assertSame(0, $api->getServiceBlockTtl());
        $this->assertSame(0, $api->getServiceBlockTtl());
    }

    public function test_throttle_throws_pre_http_when_block_registered(): void
    {
        Redis::shouldReceive('ttl')
            ->once()
            ->with('api-remote-block:rate-limit-block-test')
            ->andReturn(90);

        $mockHandler = new MockHandler([new Response(200, [], '{}')]);
        $api         = new RateLimitBlockTestApi;
        $api->setOverrideClient($this->mockClient($mockHandler));

        try {
            $api->get('endpoint');
            $this->fail('Expected RateLimitExceededException was not thrown');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(90, $exception->retryAfterSeconds);
            $this->assertStringContainsString('rate-limit-block-test', $exception->getMessage());
        }

        // The mock response was never consumed — no HTTP call happened
        $this->assertSame(1, $mockHandler->count());
    }

    public function test_429_registers_block_and_throws_typed_exception_honoring_retry_after(): void
    {
        config(['danx.errors.api_retry_count' => 3]);

        // Not blocked before the call, then the 429 registers a 120s block
        Redis::shouldReceive('ttl')->once()->andReturn(-2);
        Redis::shouldReceive('setex')
            ->once()
            ->with('api-remote-block:rate-limit-block-test', 120, 1);

        // Single queued response: 120s exceeds the 30s inline max, so no retry
        // may consume a second response
        $mockHandler = new MockHandler([
            new Response(429, ['Retry-After' => '120'], 'You are Blocked'),
        ]);
        $api = new RateLimitBlockTestApi;
        $api->setOverrideClient($this->mockClient($mockHandler));

        try {
            $api->get('endpoint');
            $this->fail('Expected RateLimitExceededException was not thrown');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(120, $exception->retryAfterSeconds);
            $this->assertStringContainsString('rate-limit-block-test', $exception->getMessage());
            $this->assertStringContainsString('You are Blocked', $exception->getMessage());
        }

        // Exactly one HTTP call — retries remained but the long block threw upward
        $this->assertSame(0, $mockHandler->count());
    }

    public function test_429_with_short_retry_after_waits_inline_and_retries(): void
    {
        config(['danx.errors.api_retry_count' => 3]);

        Redis::shouldReceive('ttl')->once()->andReturn(-2);
        Redis::shouldReceive('setex')
            ->once()
            ->with('api-remote-block:rate-limit-block-test', 1, 1);

        $mockHandler = new MockHandler([
            new Response(429, ['Retry-After' => '1'], 'slow down'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $api = new RateLimitBlockTestApi;
        $api->setOverrideClient($this->mockClient($mockHandler));

        $api->get('endpoint');

        // The inline wait retried and consumed the second (successful) response
        $this->assertSame(0, $mockHandler->count());
        $this->assertTrue($api->json('ok'));
    }

    public function test_parse_rate_limit_block_seconds_override_used_without_retry_after_header(): void
    {
        Redis::shouldReceive('ttl')->once()->andReturn(-2);
        Redis::shouldReceive('setex')
            ->once()
            ->with('api-remote-block:rate-limit-parse-override-test', 3600, 1);

        $mockHandler = new MockHandler([
            new Response(429, [], 'You are Blocked for 1 Hour'),
        ]);
        $api = new RateLimitParseOverrideTestApi;
        $api->setOverrideClient($this->mockClient($mockHandler));

        try {
            $api->get('endpoint');
            $this->fail('Expected RateLimitExceededException was not thrown');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(3600, $exception->retryAfterSeconds);
        }
    }

    public function test_429_without_header_or_override_falls_back_to_config_default(): void
    {
        config(['danx.errors.rate_limit_block_default_seconds' => 60]);

        Redis::shouldReceive('ttl')->once()->andReturn(-2);
        Redis::shouldReceive('setex')
            ->once()
            ->with('api-remote-block:rate-limit-block-test', 60, 1);

        $mockHandler = new MockHandler([
            new Response(429, [], 'rate limited'),
        ]);
        $api = new RateLimitBlockTestApi;
        $api->setOverrideClient($this->mockClient($mockHandler));

        try {
            $api->get('endpoint');
            $this->fail('Expected RateLimitExceededException was not thrown');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(60, $exception->retryAfterSeconds);
        }
    }
}

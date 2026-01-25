<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApiCurlOptionsTest extends TestCase
{
    /**
     * Test that array_merge renumbers integer keys (demonstrating the bug).
     * This shows why we can't use array_merge for CURLOPT_* constants.
     */
    public function test_array_merge_renumbers_integer_keys(): void
    {
        $existing = [];
        $new = [CURLOPT_NOSIGNAL => true]; // CURLOPT_NOSIGNAL = 99

        $merged = array_merge($existing, $new);

        // array_merge renumbers integer keys starting from 0
        $this->assertArrayHasKey(0, $merged);
        $this->assertArrayNotHasKey(CURLOPT_NOSIGNAL, $merged);
    }

    /**
     * Test that + operator preserves integer keys (the correct approach).
     */
    public function test_plus_operator_preserves_integer_keys(): void
    {
        $existing = [];
        $new = [CURLOPT_NOSIGNAL => true]; // CURLOPT_NOSIGNAL = 99

        $merged = $existing + $new;

        // + operator preserves the original integer key
        $this->assertArrayHasKey(CURLOPT_NOSIGNAL, $merged);
        $this->assertTrue($merged[CURLOPT_NOSIGNAL]);
    }

    /**
     * Test that existing curl options are preserved when adding CURLOPT_NOSIGNAL.
     */
    public function test_plus_operator_preserves_existing_options(): void
    {
        $existing = [CURLOPT_TIMEOUT => 30];
        $new = [CURLOPT_NOSIGNAL => true];

        $merged = $existing + $new;

        $this->assertArrayHasKey(CURLOPT_TIMEOUT, $merged);
        $this->assertArrayHasKey(CURLOPT_NOSIGNAL, $merged);
        $this->assertEquals(30, $merged[CURLOPT_TIMEOUT]);
        $this->assertTrue($merged[CURLOPT_NOSIGNAL]);
    }
}

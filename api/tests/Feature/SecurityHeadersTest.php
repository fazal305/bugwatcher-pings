<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/health')->assertOk());
        $this->assertSecurityHeaders($this->getJson('/api/me')->assertUnauthorized());
        $this->assertSecurityHeaders($this->postJson('/api/cron/run')->assertUnauthorized());
    }

    public function test_the_ping_route_carries_them_too(): void
    {
        Queue::fake();
        $key = Project::factory()->create()->public_key;

        $response = $this->postJson('/api/v1/ping', [
            'message' => 'Boom', 'level' => 'error', 'environment' => 'production', 'timestamp' => 1760000000000,
        ], ['X-BugWatcher-Key' => $key])->assertStatus(202);

        $this->assertSecurityHeaders($response);
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->getJson('/api/health')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/api/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_unknown_api_route_returns_json_404(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_server_errors_hide_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/boom', fn () => throw new RuntimeException('secret internal detail'));

        $response = $this->get('/api/boom');

        $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
    }

    public function test_cors_allows_only_the_configured_frontend(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5473'])
            ->getJson('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5473');

        $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/health');
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}

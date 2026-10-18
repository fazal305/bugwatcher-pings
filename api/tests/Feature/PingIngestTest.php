<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PingIngestTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
    }

    private function validPing(array $overrides = []): array
    {
        return array_replace([
            'message' => 'Cannot read properties of undefined',
            'level' => 'error',
            'environment' => 'production',
            'timestamp' => 1760000000000,
        ], $overrides);
    }

    private function ping(array|string $payload, ?string $key = null, string $ip = '203.0.113.10', array $server = []): TestResponse
    {
        $server += ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
        if ($key !== '') {
            $server['HTTP_X_BUGWATCHER_KEY'] = $key ?? $this->project->public_key;
        }

        return $this->call('POST', '/api/v1/ping', server: $server, content: is_string($payload) ? $payload : json_encode($payload));
    }

    private function pushedPayload(): array
    {
        $payload = null;
        Queue::assertPushedOn('ingest', ProcessPing::class, function (ProcessPing $job) use (&$payload) {
            $payload = $job->payload;

            return true;
        });

        return $payload;
    }

    public function test_a_valid_ping_is_queued_and_accepted(): void
    {
        Queue::fake();

        $response = $this->ping($this->validPing(['release' => '1.4.0', 'sdk' => ['name' => 'bugwatcher-js', 'version' => '0.1.0']]))
            ->assertStatus(202);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $response->json('id'));
        $this->assertMatchesRegularExpression('/^ingest;dur=[\d.]+/', $response->headers->get('Server-Timing'));

        $payload = $this->pushedPayload();
        $this->assertSame($response->json('id'), $payload['id']);
        $this->assertSame($this->project->id, $payload['project_id']);
        $this->assertSame(1760000000000, $payload['occurred_at']);
        $this->assertSame('1.4.0', $payload['release']);
        $this->assertIsInt($payload['received_at']);
        $this->assertArrayNotHasKey('key', $payload);
    }

    public function test_iso_timestamps_and_the_key_in_a_text_plain_body_are_accepted(): void
    {
        Queue::fake();

        $this->ping(
            json_encode($this->validPing(['timestamp' => '2026-10-18T12:00:00.250Z', 'key' => $this->project->public_key])),
            key: '',
            server: ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
        )->assertStatus(202);

        $this->assertSame(1792324800250, $this->pushedPayload()['occurred_at']);
    }

    public function test_unknown_keys_are_cached_too(): void
    {
        Queue::fake();
        $this->ping($this->validPing())->assertStatus(202);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->ping($this->validPing())->assertStatus(202);
        $this->ping($this->validPing(), key: 'bwp_pk_unknown')->assertUnauthorized();
        $this->ping($this->validPing(), key: 'bwp_pk_unknown')->assertUnauthorized();

        // The first unknown-key lookup reaches the database; the repeat is answered from the negative cache.
        $this->assertSame(1, $queries);
    }

    public function test_cached_hit_runs_zero_queries(): void
    {
        Queue::fake();
        $this->ping($this->validPing())->assertStatus(202);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->ping($this->validPing())->assertStatus(202);

        $this->assertSame(0, $queries);
    }

    public function test_a_known_key_keeps_working_while_the_database_is_unreachable(): void
    {
        Queue::fake();
        $this->ping($this->validPing())->assertStatus(202);
        $this->travel(config('pings.key_cache_ttl') + 5)->seconds();

        // Makes every project lookup fail, as it would with the database down; rolled back with the test transaction.
        Schema::rename('projects', 'projects_unreachable');

        $this->ping($this->validPing())->assertStatus(202);
        $this->ping($this->validPing(), key: 'bwp_pk_nevercached')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertExactJson(['message' => 'The service is temporarily unavailable. Please retry shortly.']);
    }

    public function test_unknown_or_missing_keys_get_a_generic_401(): void
    {
        Queue::fake();

        $this->ping($this->validPing(), key: 'bwp_pk_doesnotexist')->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
        $this->ping($this->validPing(), key: 'not-a-key')->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
        $this->ping($this->validPing(), key: '')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_rotated_key_stops_working_straight_away(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin, ProjectRole::Admin)->create();
        $oldKey = $project->public_key;
        $this->ping($this->validPing(), key: $oldKey)->assertStatus(202);

        Sanctum::actingAs($admin);
        $newKey = $this->postJson("/api/projects/{$project->slug}/rotate-key")->assertOk()->json('data.public_key');

        $this->ping($this->validPing(), key: $oldKey)->assertUnauthorized();
        $this->ping($this->validPing(), key: $newKey)->assertStatus(202);
    }

    public function test_invalid_pings_get_422_with_field_errors(): void
    {
        Queue::fake();

        $this->ping(['level' => 'panic', 'environment' => 'qa', 'timestamp' => 'yesterday-ish', 'exception' => ['frames' => 'nope']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message', 'level', 'environment', 'timestamp', 'exception.frames']);

        $this->ping($this->validPing(['message' => str_repeat('a', 2001), 'timestamp' => 1760000000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message', 'timestamp']);

        $this->ping($this->validPing(['exception' => ['type' => 'TypeError', 'frames' => [['file' => 'a.js', 'line' => 'ten']]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exception.frames.0.line']);

        $this->ping('{not json')->assertStatus(422);
        $this->ping('[1,2,3]')->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_oversized_pings_get_413(): void
    {
        Queue::fake();

        $this->ping($this->validPing(['message' => 'x', 'request' => ['body' => str_repeat('a', 70000)]]))
            ->assertStatus(413)
            ->assertJsonPath('message', 'The ping is too large. The limit is 64 KB.');

        Queue::assertNothingPushed();
    }

    public function test_frames_and_context_lines_are_capped(): void
    {
        Queue::fake();
        $frame = [
            'file' => 'app.js', 'line' => 10, 'function' => 'run', 'context_line' => 'boom()',
            'pre_context' => array_map(fn ($i) => "pre {$i}", range(1, 15)),
            'post_context' => array_map(fn ($i) => "post {$i}", range(1, 15)),
        ];

        $this->ping($this->validPing(['exception' => ['type' => 'Error', 'frames' => array_fill(0, 60, $frame)]]))->assertStatus(202);

        $frames = $this->pushedPayload()['exception']['frames'];
        $this->assertCount(50, $frames);
        $this->assertSame(array_map(fn ($i) => "pre {$i}", range(6, 15)), $frames[0]['pre_context']);
        $this->assertSame(array_map(fn ($i) => "post {$i}", range(1, 10)), $frames[0]['post_context']);
    }

    public function test_sensitive_values_are_masked_before_the_payload_is_queued(): void
    {
        Queue::fake();

        $this->ping($this->validPing([
            'request' => [
                'method' => 'POST',
                'url' => 'https://shop.test/checkout?page=2&access_token=abc123&Card%5Bnumber%5D=4111#step-2',
                'headers' => ['Authorization' => 'Bearer s3cret', 'COOKIE' => 'sid=1', 'Set-Cookie' => 'a=b', 'X-Api-Key' => 'k1', 'Accept' => 'application/json'],
                'query' => ['page' => '2', 'refreshToken' => 'r1'],
                'body' => [
                    'email' => 'a@example.com',
                    'user_password' => 'hunter2',
                    'className' => 'btn',
                    'payment' => ['CVV' => '123', 'Credit_Card' => ['number' => '4111'], 'notes' => [['ssn' => '078-05-1120', 'ok' => 'yes']]],
                    'clientSecret' => 'cs',
                ],
            ],
            'user' => ['id' => 42, 'password' => 'should-not-survive'],
        ]))->assertStatus(202);

        $request = $this->pushedPayload()['request'];

        $this->assertSame('https://shop.test/checkout?page=2&access_token=[Filtered]&Card%5Bnumber%5D=[Filtered]#step-2', $request['url']);
        $this->assertSame([
            'Authorization' => '[Filtered]', 'COOKIE' => '[Filtered]', 'Set-Cookie' => '[Filtered]', 'X-Api-Key' => '[Filtered]', 'Accept' => 'application/json',
        ], $request['headers']);
        $this->assertSame(['page' => '2', 'refreshToken' => '[Filtered]'], $request['query']);
        $this->assertSame([
            'email' => 'a@example.com',
            'user_password' => '[Filtered]',
            'className' => 'btn',
            'payment' => ['CVV' => '[Filtered]', 'Credit_Card' => '[Filtered]', 'notes' => [['ssn' => '[Filtered]', 'ok' => 'yes']]],
            'clientSecret' => '[Filtered]',
        ], $request['body']);

        $serialized = serialize($this->pushedPayload());
        foreach (['s3cret', 'hunter2', 'abc123', '4111', '078-05-1120', 'r1', 'should-not-survive'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_a_query_string_given_as_text_is_masked(): void
    {
        Queue::fake();

        $this->ping($this->validPing(['request' => ['query' => '?q=shoes&api_key=zzz']]))->assertStatus(202);

        $this->assertSame('q=shoes&api_key=[Filtered]', $this->pushedPayload()['request']['query']);
    }

    public function test_pings_are_rate_limited_per_key(): void
    {
        Queue::fake();
        $this->freezeTime();
        config(['pings.rate_limit_per_key' => 2, 'pings.rate_limit_per_ip' => 100]);

        $this->ping($this->validPing(), ip: '198.51.100.1')->assertStatus(202);
        $this->ping($this->validPing(), ip: '198.51.100.2')->assertStatus(202);
        $this->ping($this->validPing(), ip: '198.51.100.3')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Too many pings. Please slow down and try again in a moment.');

        $other = Project::factory()->create();
        $this->ping($this->validPing(), key: $other->public_key, ip: '198.51.100.3')->assertStatus(202);

        $this->travel(1)->seconds();
        $this->ping($this->validPing(), ip: '198.51.100.4')->assertStatus(202);
    }

    public function test_pings_are_rate_limited_per_ip(): void
    {
        Queue::fake();
        $this->freezeTime();
        config(['pings.rate_limit_per_key' => 100, 'pings.rate_limit_per_ip' => 2]);
        [$a, $b, $c] = Project::factory()->count(3)->create();

        $this->ping($this->validPing(), key: $a->public_key)->assertStatus(202);
        $this->ping($this->validPing(), key: $b->public_key)->assertStatus(202);
        $this->ping($this->validPing(), key: $c->public_key)->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->ping($this->validPing(), key: $c->public_key, ip: '203.0.113.99')->assertStatus(202);
    }

    public function test_rate_limits_work_on_the_redis_store(): void
    {
        $this->requireRedis();
        Queue::fake();
        $this->freezeTime();
        config(['pings.cache_store' => 'redis', 'pings.rate_limit_per_key' => 2, 'pings.rate_limit_per_ip' => 100]);
        $ip = '192.0.2.'.random_int(1, 254);
        $project = Project::factory()->create();

        $this->ping($this->validPing(), key: $project->public_key, ip: $ip)->assertStatus(202);
        $this->ping($this->validPing(), key: $project->public_key, ip: $ip)->assertStatus(202);
        $this->ping($this->validPing(), key: $project->public_key, ip: $ip)->assertStatus(429);
    }

    public function test_cors_allows_any_origin_on_the_ping_route_only(): void
    {
        Queue::fake();
        $preflight = ['HTTP_ORIGIN' => 'https://customer-site.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'];

        $this->call('OPTIONS', '/api/v1/ping', server: $preflight)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-BugWatcher-Key');

        $this->ping($this->validPing(), server: ['HTTP_ORIGIN' => 'https://customer-site.example'])
            ->assertStatus(202)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        // Elsewhere only the dashboard's origin is ever allowed.
        $this->assertSame('http://localhost:5473', $this->call('OPTIONS', '/api/projects', server: $preflight)->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('http://localhost:5473', $this->getJson('/api/health', ['Origin' => 'https://customer-site.example'])->headers->get('Access-Control-Allow-Origin'));
        $this->getJson('/api/health', ['Origin' => 'http://localhost:5473'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5473');
    }

    public function test_a_ping_flows_through_the_queue_into_a_masked_event(): void
    {
        $response = $this->ping($this->validPing([
            'exception' => ['type' => 'TypeError', 'frames' => [['file' => 'https://shop.test/assets/app.js', 'line' => 12, 'function' => 'pay']]],
            'request' => ['url' => 'https://shop.test/pay?password=pw', 'headers' => ['Authorization' => 'Bearer t']],
        ]))->assertStatus(202);

        $event = ErrorEvent::findOrFail($response->json('id'));
        $this->assertSame('https://shop.test/pay?password=[Filtered]', $event->request['url']);
        $this->assertSame('[Filtered]', $event->request['headers']['Authorization']);
        $this->assertSame('/assets/app.js', $event->file);
        $this->assertSame(1, $event->issue->event_count);
    }
}

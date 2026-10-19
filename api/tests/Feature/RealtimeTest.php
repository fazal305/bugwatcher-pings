<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Events\PingProcessed;
use App\Jobs\ProcessPing;
use App\Models\Project;
use App\Models\User;
use App\Realtime\Jwt;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET_ID = '123456.7890123';

    private const FEED_FIELDS = [
        'id', 'issue_id', 'issue_title', 'level', 'environment', 'critical', 'message', 'culprit',
        'occurred_at', 'received_at', 'is_new_issue', 'is_regression', 'is_sample', 'event_count',
    ];

    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        ]);
    }

    private function tokenFor(User $user): string
    {
        Sanctum::actingAs($user);
        $token = $this->postJson('/api/realtime/token')->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function authorize(?string $bearer, string $channel, string $socketId = self::SOCKET_ID): TestResponse
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer];

        return $this->postJson('/api/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel], $headers);
    }

    private function payload(Project $project, array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::ulid(),
            'project_id' => $project->id,
            'received_at' => 1760000001234,
            'occurred_at' => 1760000000567,
            'message' => str_repeat('x', 400),
            'level' => 'error',
            'environment' => 'production',
            'exception' => ['type' => 'TypeError', 'frames' => [['file' => '/app/cart.js', 'line' => 9, 'function' => 'total', 'context_line' => 'secret()']]],
            'request' => ['method' => 'POST', 'url' => 'https://shop.test/pay', 'headers' => ['Cookie' => '[Filtered]']],
            'user' => ['id' => 'customer-42'],
            'release' => '1.2.0',
            'sdk' => ['name' => 'bugwatcher-js', 'version' => '0.1.0'],
        ], $overrides);
    }

    public function test_the_token_endpoint_needs_a_sanctum_session(): void
    {
        $this->postJson('/api/realtime/token')->assertUnauthorized();
    }

    public function test_the_token_is_a_five_minute_hs256_jwt_listing_the_users_projects(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->withMember($user, ProjectRole::Developer)->create();
        Project::factory()->withMember(User::factory()->create())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/realtime/token')->assertOk()->assertJsonPath('expires_in', 300);
        $claims = app(Jwt::class)->decode($response->json('token'));

        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('realtime', $claims['aud']);
        $this->assertSame([$mine->slug], $claims['projects']);
        $this->assertEqualsWithDelta(time() + 300, $claims['exp'], 2);
    }

    public function test_a_member_with_a_valid_token_gets_a_pusher_signature(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user, ProjectRole::Developer)->create();
        $channel = 'private-project.'.$project->slug;

        $this->authorize($this->tokenFor($user), $channel)
            ->assertOk()
            ->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', self::SOCKET_ID.':'.$channel, 'test-secret')]);
    }

    public function test_a_non_member_is_forbidden_even_with_a_valid_token(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        Project::factory()->withMember($user)->create();
        $other = Project::factory()->withMember(User::factory()->create())->create();

        $this->authorize($this->tokenFor($user), 'private-project.'.$other->slug)->assertForbidden();
        $this->authorize($this->tokenFor($user), 'private-project.no-such-project')->assertForbidden();
    }

    public function test_membership_is_rechecked_in_the_database_after_the_token_was_issued(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user, ProjectRole::Developer)->create();
        $token = $this->tokenFor($user);

        $project->members()->where('user_id', $user->id)->delete();

        $this->authorize($token, 'private-project.'.$project->slug)->assertForbidden();
    }

    public function test_a_project_joined_after_the_token_was_issued_needs_a_fresh_token(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $project = Project::factory()->withMember($user)->create();

        $this->authorize($token, 'private-project.'.$project->slug)->assertForbidden();
        $this->authorize($this->tokenFor($user), 'private-project.'.$project->slug)->assertOk();
    }

    public function test_expired_tampered_or_missing_tokens_get_401(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();
        $channel = 'private-project.'.$project->slug;
        $jwt = app(Jwt::class);
        $claims = ['sub' => (string) $user->id, 'aud' => 'realtime', 'projects' => [$project->slug]];

        $expired = $jwt->encode($claims + ['exp' => time() - 1]);
        [$header, $payload, $signature] = explode('.', $jwt->encode($claims + ['exp' => time() + 60]));
        $tampered = $header.'.'.rtrim(strtr(base64_encode(json_encode(['sub' => '999'] + $claims + ['exp' => time() + 60])), '+/', '-_'), '=').'.'.$signature;
        $wrongAudience = $jwt->encode(['aud' => 'other', 'exp' => time() + 60] + $claims);

        $this->authorize($expired, $channel)->assertUnauthorized();
        $this->authorize($tampered, $channel)->assertUnauthorized();
        $this->authorize($wrongAudience, $channel)->assertUnauthorized();
        $this->authorize(null, $channel)->assertUnauthorized();
    }

    public function test_a_sanctum_token_alone_is_not_accepted(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();

        $this->authorize($user->issueToken(), 'private-project.'.$project->slug)->assertUnauthorized();
    }

    public function test_other_channels_and_bad_socket_ids_are_rejected(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();
        $token = $this->tokenFor($user);

        $this->authorize($token, 'project.'.$project->slug)->assertStatus(422);
        $this->authorize($token, 'presence-project.'.$project->slug)->assertStatus(422);
        $this->authorize($token, 'private-project.'.$project->slug, socketId: 'abc')->assertStatus(422);
    }

    public function test_non_string_channel_names_and_socket_ids_get_422_not_500(): void
    {
        $this->useReverb();
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];
        $channel = 'private-project.'.$project->slug;

        $this->postJson('/api/broadcasting/auth', ['socket_id' => self::SOCKET_ID, 'channel_name' => [$channel]], $headers)->assertStatus(422);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => [self::SOCKET_ID], 'channel_name' => $channel], $headers)->assertStatus(422);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => self::SOCKET_ID, 'channel_name' => ['x' => 'private-project.x']], $headers)->assertStatus(422);
    }

    public function test_processing_a_ping_broadcasts_only_the_feed_fields_on_the_projects_private_channel(): void
    {
        $sent = [];
        Broadcast::extend('spy', function () use (&$sent) {
            return new class($sent) extends Broadcaster
            {
                public function __construct(private array &$sent) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
                }
            };
        });
        config(['broadcasting.connections.spy' => ['driver' => 'spy'], 'broadcasting.default' => 'spy']);
        $project = Project::factory()->create();
        $payload = $this->payload($project);

        ProcessPing::dispatchSync($payload);

        $this->assertCount(1, $sent);
        $this->assertSame(['private-project.'.$project->slug], $sent[0]['channels']);
        $this->assertSame('ping.processed', $sent[0]['event']);
        $data = $sent[0]['payload'];
        unset($data['socket']);
        $this->assertSame(self::FEED_FIELDS, array_keys($data));
        $this->assertSame($payload['id'], $data['id']);
        $this->assertTrue($data['critical']);
        $this->assertTrue($data['is_new_issue']);
        $this->assertFalse($data['is_regression']);
        $this->assertSame(1, $data['event_count']);
        $this->assertSame('/app/cart.js:9', $data['culprit']);
        $this->assertSame('2025-10-09T08:53:20.567Z', $data['occurred_at']);
        $this->assertSame('2025-10-09T08:53:21.234Z', $data['received_at']);
        $this->assertSame(300, mb_strlen($data['message']));
        $this->assertStringNotContainsString('customer-42', json_encode($sent[0]['payload']));
        $this->assertStringNotContainsString('shop.test', json_encode($sent[0]['payload']));
        $this->assertStringNotContainsString('secret()', json_encode($sent[0]['payload']));
    }

    public function test_only_production_fatal_and_error_events_are_critical(): void
    {
        Event::fake([PingProcessed::class]);
        $project = Project::factory()->create();

        foreach ([['fatal', 'production', true], ['error', 'staging', false], ['warning', 'production', false], ['info', 'development', false]] as [$level, $environment, $critical]) {
            ProcessPing::dispatchSync($this->payload($project, ['level' => $level, 'environment' => $environment, 'message' => "$level $environment"]));
            Event::assertDispatched(PingProcessed::class, fn (PingProcessed $e) => $e instanceof ShouldBroadcastNow
                && $e->event->message === "$level $environment"
                && $e->broadcastWith()['critical'] === $critical);
        }
    }

    public function test_a_broadcast_failure_does_not_fail_the_job(): void
    {
        Broadcast::extend('down', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Connection refused');
            }
        });
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
        $project = Project::factory()->create();
        $payload = $this->payload($project);

        ProcessPing::dispatchSync($payload);

        $this->assertDatabaseHas('events', ['id' => $payload['id']]);
    }
}

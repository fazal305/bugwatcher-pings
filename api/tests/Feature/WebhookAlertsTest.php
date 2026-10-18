<?php

namespace Tests\Feature;

use App\Jobs\ProcessPing;
use App\Jobs\SendWebhook;
use App\Models\ErrorEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Cooldown;
use App\Webhooks\HostResolver;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var array<string, list<string>> */
    private array $dns = [
        'hooks.slack.com' => ['54.230.10.1'],
        'discord.com' => ['162.159.135.232'],
        'hooks.example.com' => ['93.184.216.34'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['webhooks.frontend_url' => 'https://app.bugwatcher.test', 'webhooks.retry_delays' => [1, 1]]);
        $this->project = Project::factory()->create(['name' => 'Shop']);

        $test = $this;
        $this->app->instance(HostResolver::class, new class($test) extends HostResolver
        {
            public function __construct(private WebhookAlertsTest $test) {}

            public function resolve(string $host): array
            {
                return $this->test->lookup($host);
            }
        });
    }

    /** @return list<string> */
    public function lookup(string $host): array
    {
        return $this->dns[$host] ?? [];
    }

    // Stands in for Redis in the tests that aren't about the cooldown, so they run without it.
    private function alwaysSend(): void
    {
        $this->app->instance(Cooldown::class, new class extends Cooldown
        {
            public function __construct() {}

            public function acquire(int $endpointId, int $issueId, int $seconds, bool $force): bool
            {
                return true;
            }
        });
    }

    private function realCooldown(): void
    {
        $this->requireRedis();
        $redis = Redis::connection(config('pings.redis_connection'));
        $clear = function () use ($redis) {
            $prefix = (string) config('database.redis.options.prefix');
            foreach ($redis->keys(config('webhooks.cooldown_key_prefix').'*') as $key) {
                $redis->del(substr($key, strlen($prefix)));
            }
        };
        $clear();
        $this->beforeApplicationDestroyed($clear);
    }

    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return $this->project->webhooks()->create(array_replace([
            'kind' => 'slack',
            'name' => 'Ops channel',
            'url' => 'https://hooks.slack.com/services/T000/B000/abcdEFGH',
        ], $attributes));
    }

    private function ping(array $overrides = []): string
    {
        $id = (string) Str::ulid();
        ProcessPing::dispatchSync(array_replace([
            'id' => $id,
            'project_id' => $this->project->id,
            'received_at' => (int) floor(microtime(true) * 1000),
            'occurred_at' => 1760868000123,
            'message' => 'Cart total is undefined',
            'level' => 'error',
            'environment' => 'production',
            'exception' => ['type' => 'TypeError', 'frames' => [['file' => '/app/cart.js', 'line' => 42, 'function' => 'total', 'in_app' => true]]],
        ], $overrides));

        return $id;
    }

    /** @return list<int> */
    private function queuedEndpointIds(): array
    {
        return Queue::pushed(SendWebhook::class)->map(fn (SendWebhook $job) => $job->endpointId)->sort()->values()->all();
    }

    public function test_alerts_go_to_the_webhooks_queue_for_matching_endpoints_only(): void
    {
        $this->alwaysSend();
        Queue::fake([SendWebhook::class]);
        $errorAndUp = $this->endpoint(['min_level' => 'error']);
        $fatalOnly = $this->endpoint(['min_level' => 'fatal']);
        $warningAndUp = $this->endpoint(['min_level' => 'warning']);
        $stagingOnly = $this->endpoint(['environments' => ['staging']]);
        $prodAndStaging = $this->endpoint(['environments' => ['production', 'staging'], 'min_level' => 'info']);
        $disabled = $this->endpoint(['enabled' => false]);
        $otherProject = Project::factory()->create()->webhooks()->create(['kind' => 'slack', 'name' => 'x', 'url' => 'https://hooks.slack.com/services/a']);

        $this->ping(['level' => 'error', 'environment' => 'production']);

        $this->assertSame([$errorAndUp->id, $warningAndUp->id, $prodAndStaging->id], $this->queuedEndpointIds());
        Queue::assertPushedOn('webhooks', SendWebhook::class);
        Queue::assertNotPushed(SendWebhook::class, fn (SendWebhook $job) => in_array($job->endpointId, [$fatalOnly->id, $stagingOnly->id, $disabled->id, $otherProject->id], true));
    }

    public function test_level_threshold_and_environment_filter(): void
    {
        $this->alwaysSend();
        Queue::fake([SendWebhook::class]);
        $endpoint = $this->endpoint(['min_level' => 'warning', 'environments' => ['production']]);

        $this->ping(['level' => 'info', 'message' => 'info one']);
        $this->ping(['level' => 'warning', 'environment' => 'development', 'message' => 'dev warning']);
        $this->assertSame([], $this->queuedEndpointIds());

        $this->ping(['level' => 'warning', 'message' => 'prod warning']);
        $this->ping(['level' => 'fatal', 'message' => 'prod fatal']);
        $this->assertSame([$endpoint->id, $endpoint->id], $this->queuedEndpointIds());
    }

    public function test_the_endpoint_cache_is_cleared_when_an_endpoint_changes(): void
    {
        $this->alwaysSend();
        Queue::fake([SendWebhook::class]);
        $endpoint = $this->endpoint();

        $this->ping(['message' => 'first']);
        $endpoint->update(['enabled' => false]);
        $this->ping(['message' => 'second']);
        $endpoint->update(['enabled' => true, 'min_level' => 'fatal']);
        $this->ping(['message' => 'third']);
        $endpoint->delete();
        $this->ping(['message' => 'fourth', 'level' => 'fatal']);

        $this->assertSame([$endpoint->id], $this->queuedEndpointIds());
    }

    public function test_cooldown_throttles_repeats_of_an_issue_per_endpoint(): void
    {
        $this->realCooldown();
        Queue::fake([SendWebhook::class]);
        $short = $this->endpoint(['cooldown_seconds' => 300]);
        $none = $this->endpoint(['cooldown_seconds' => 0]);

        $this->ping();
        $this->ping();
        $this->ping();

        // The first ping is a new issue (sent to both); the next two are throttled for the endpoint with a cooldown.
        $this->assertSame(1, Queue::pushed(SendWebhook::class, fn ($job) => $job->endpointId === $short->id)->count());
        $this->assertSame(3, Queue::pushed(SendWebhook::class, fn ($job) => $job->endpointId === $none->id)->count());
        $this->assertSame(2, WebhookDelivery::where('endpoint_id', $short->id)->where('status', 'throttled')->count());
        $this->assertSame(0, WebhookDelivery::where('endpoint_id', $none->id)->count());

        // A different issue has its own window.
        $this->ping(['message' => 'Other', 'exception' => ['type' => 'RangeError', 'frames' => [['file' => '/app/other.js', 'function' => 'other', 'in_app' => true]]]]);
        $this->assertSame(2, Queue::pushed(SendWebhook::class, fn ($job) => $job->endpointId === $short->id)->count());
    }

    public function test_new_issues_and_regressions_bypass_the_cooldown(): void
    {
        $this->realCooldown();
        Queue::fake([SendWebhook::class]);
        $endpoint = $this->endpoint(['cooldown_seconds' => 3600]);

        // Another worker already sent an alert for this issue recently.
        $this->ping();
        $issue = Issue::sole();
        $this->assertCount(1, Queue::pushed(SendWebhook::class));

        $this->ping();
        $this->assertCount(1, Queue::pushed(SendWebhook::class), 'a repeat inside the window is throttled');

        $issue->update(['status' => 'resolved']);
        $this->ping();
        $pushed = Queue::pushed(SendWebhook::class);
        $this->assertCount(2, $pushed, 'a regression is sent despite the window');
        $this->assertTrue($pushed->last()->alert['is_regression']);
        $this->assertSame($endpoint->id, $pushed->last()->endpointId);

        $this->ping();
        $this->assertCount(2, Queue::pushed(SendWebhook::class), 'the regression started a new window');
    }

    public function test_a_delivery_is_sent_signed_with_a_deep_link_and_recorded_with_its_dispatch_delay(): void
    {
        $this->alwaysSend();
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('ok', 200);
        });
        $this->project->webhooks()->create(['kind' => 'generic', 'name' => 'Receiver', 'url' => 'https://hooks.example.com/bugwatcher', 'signing_secret' => 'whsec_abc']);

        $eventId = $this->ping();

        $deep = 'https://app.bugwatcher.test/app/projects/'.$this->project->slug.'/events/'.$eventId;
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($deep) {
            [$t, $v1] = array_map(fn ($part) => explode('=', $part, 2)[1], explode(',', $request->header('X-BugWatcher-Signature')[0]));

            return $request->url() === 'https://hooks.example.com/bugwatcher'
                && $request['event']['url'] === $deep
                && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), 'whsec_abc'), $v1);
        });

        $this->assertFalse($seen[0]['allow_redirects']);
        $this->assertSame(2, $seen[0]['connect_timeout']);
        $this->assertSame(5, $seen[0]['timeout']);
        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $seen[0]['curl'][CURLOPT_RESOLVE]);

        $delivery = WebhookDelivery::sole();
        $this->assertSame('success', $delivery->status);
        $this->assertSame(200, $delivery->http_status);
        $this->assertSame($eventId, $delivery->event_id);
        $this->assertSame(ErrorEvent::find($eventId)->issue_id, $delivery->issue_id);
        $this->assertNotNull($delivery->dispatch_delay_ms);
        $this->assertGreaterThanOrEqual(0, $delivery->dispatch_delay_ms);
        $this->assertLessThan(5000, $delivery->dispatch_delay_ms);
        $this->assertNotNull($delivery->duration_ms);

        $endpoint = WebhookEndpoint::sole();
        $this->assertSame('success', $endpoint->last_status);
        $this->assertNull($endpoint->last_error);
        $this->assertNotNull($endpoint->last_delivered_at);
    }

    public function test_redirects_are_not_followed_and_count_as_failures(): void
    {
        $this->alwaysSend();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        $this->endpoint();

        $this->ping();

        Http::assertSentCount(1);
        $delivery = WebhookDelivery::sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(302, $delivery->http_status);
        $this->assertSame('The endpoint answered HTTP 302.', WebhookEndpoint::sole()->last_error);
    }

    public function test_server_errors_are_retried_up_to_three_times_but_client_errors_are_not(): void
    {
        $this->alwaysSend();
        Http::fakeSequence()->push('busy', 503)->push('busy', 500)->push('ok', 200)
            ->push('{"error":"secret https://hooks.slack.com/services/T000/B000/abcdEFGH"}', 404);
        $this->endpoint();

        $this->ping();

        Http::assertSentCount(3);
        $this->assertSame('success', WebhookDelivery::sole()->status);

        WebhookDelivery::query()->delete();
        $this->ping(['message' => 'Another', 'level' => 'fatal']);

        Http::assertSentCount(4);
        $this->assertSame(1, WebhookDelivery::count());
        $endpoint = WebhookEndpoint::sole();
        $this->assertSame('failed', $endpoint->last_status);
        $this->assertSame('The endpoint answered HTTP 404.', $endpoint->last_error);
    }

    public function test_the_url_is_checked_again_at_send_time(): void
    {
        $this->alwaysSend();
        Http::fake();
        $this->project->webhooks()->create(['kind' => 'generic', 'name' => 'Receiver', 'url' => 'https://hooks.example.com/bugwatcher', 'signing_secret' => 'x']);

        // The name now points at the cloud metadata address (DNS rebinding, or a changed record).
        $this->dns['hooks.example.com'] = ['169.254.169.254'];
        $this->ping();

        Http::assertNothingSent();
        $this->assertSame('failed', WebhookDelivery::sole()->status);
        $this->assertSame('Blocked: this URL is no longer allowed.', WebhookEndpoint::sole()->last_error);
    }

    public function test_alerts_are_still_queued_when_broadcasting_fails(): void
    {
        $this->alwaysSend();
        Queue::fake([SendWebhook::class]);
        Broadcast::extend('failing', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('WebSocket server unreachable.');
            }
        });
        config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);
        $endpoint = $this->endpoint();

        $this->ping();

        $this->assertSame([$endpoint->id], $this->queuedEndpointIds());
    }

    public function test_a_failure_while_queueing_alerts_does_not_fail_the_ping(): void
    {
        $this->app->instance(Cooldown::class, new class extends Cooldown
        {
            public function __construct() {}

            public function acquire(int $endpointId, int $issueId, int $seconds, bool $force): bool
            {
                throw new \RuntimeException('boom');
            }
        });
        $this->endpoint();

        $id = $this->ping();

        $this->assertNotNull(ErrorEvent::find($id));
    }
}

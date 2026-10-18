<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\HostResolver;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/abcdEFGH';

    private User $admin;

    private User $developer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'hooks.slack.com' => ['54.230.10.1'],
                    'discord.com' => ['162.159.135.232'],
                    'hooks.example.com' => ['93.184.216.34'],
                    'internal.example.com' => ['10.1.2.3'],
                    'localhost' => ['127.0.0.1'],
                    default => [],
                };
            }
        });

        $this->admin = User::factory()->create();
        $this->developer = User::factory()->create();
        $this->project = Project::factory()->withMember($this->admin)->withMember($this->developer, ProjectRole::Developer)->create();
    }

    private function base(?Project $project = null): string
    {
        return '/api/projects/'.($project ?? $this->project)->slug.'/webhooks';
    }

    private function slackEndpoint(?Project $project = null): WebhookEndpoint
    {
        return ($project ?? $this->project)->webhooks()->create(['kind' => 'slack', 'name' => 'Ops', 'url' => self::SLACK_URL]);
    }

    public function test_an_admin_creates_a_slack_endpoint_and_never_gets_the_url_back(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson($this->base(), [
            'kind' => 'slack', 'name' => 'Ops channel', 'url' => self::SLACK_URL,
            'min_level' => 'warning', 'environments' => ['production', 'staging'], 'cooldown_seconds' => 60,
        ])->assertCreated()
            ->assertJsonPath('data.url_masked', 'hooks.slack.com/…EFGH')
            ->assertJsonPath('data.min_level', 'warning')
            ->assertJsonPath('data.environments', ['production', 'staging'])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonMissingPath('data.url')
            ->assertJsonMissingPath('data.signing_secret');
        $this->assertStringNotContainsString('T000/B000', $response->getContent());

        $list = $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('T000/B000', $list->getContent());
    }

    public function test_defaults_match_the_schema(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->base(), ['kind' => 'discord', 'name' => 'Discord', 'url' => 'https://discord.com/api/webhooks/1/tok3n'])
            ->assertCreated()
            ->assertJsonPath('data.min_level', 'error')
            ->assertJsonPath('data.environments', ['production'])
            ->assertJsonPath('data.cooldown_seconds', 300)
            ->assertJsonPath('data.enabled', true);
    }

    public function test_a_generic_secret_is_shown_once_and_both_url_and_secret_are_encrypted_at_rest(): void
    {
        Sanctum::actingAs($this->admin);

        $secret = $this->postJson($this->base(), ['kind' => 'generic', 'name' => 'Receiver', 'url' => 'https://hooks.example.com/in?token=s3cr3t'])
            ->assertCreated()
            ->assertJsonPath('data.signed', true)
            ->json('data.signing_secret');
        $this->assertMatchesRegularExpression('/\Awhsec_[A-Za-z0-9]{40}\z/', $secret);

        $list = $this->getJson($this->base())->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString('s3cr3t', $list->getContent());

        $raw = DB::table('webhook_endpoints')->sole();
        $this->assertStringNotContainsString('hooks.example.com', $raw->url);
        $this->assertStringNotContainsString($secret, $raw->signing_secret);
        $this->assertSame('https://hooks.example.com/in?token=s3cr3t', Crypt::decryptString($raw->url));
        $this->assertSame($secret, Crypt::decryptString($raw->signing_secret));
    }

    public function test_urls_are_validated_with_field_errors(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->base(), ['kind' => 'slack', 'name' => 'x', 'url' => 'https://hooks.slack.com.evil.test/services/x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['url' => 'Slack webhook URLs start with https://hooks.slack.com/.']);
        $this->postJson($this->base(), ['kind' => 'generic', 'name' => 'x', 'url' => 'https://internal.example.com/x'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson($this->base(), ['kind' => 'generic', 'name' => 'x', 'url' => 'http://2130706433/'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson($this->base(), ['kind' => 'sms', 'name' => '', 'url' => self::SLACK_URL, 'environments' => [], 'min_level' => 'panic', 'cooldown_seconds' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'name', 'environments', 'min_level', 'cooldown_seconds']);

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_an_update_revalidates_a_new_url_and_keeps_the_old_one_otherwise(): void
    {
        $endpoint = $this->slackEndpoint();
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->base()."/{$endpoint->id}", ['url' => 'https://discord.com/api/webhooks/1/x'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->patchJson($this->base()."/{$endpoint->id}", ['kind' => 'generic'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');

        $this->patchJson($this->base()."/{$endpoint->id}", ['enabled' => false, 'name' => 'Renamed', 'environments' => ['staging']])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.name', 'Renamed');
        $this->assertSame(self::SLACK_URL, $endpoint->fresh()->url);

        $this->patchJson($this->base()."/{$endpoint->id}", ['url' => 'https://hooks.slack.com/services/T1/B1/zzzz9999'])
            ->assertOk()->assertJsonPath('data.url_masked', 'hooks.slack.com/…9999');
    }

    public function test_an_admin_deletes_an_endpoint(): void
    {
        $endpoint = $this->slackEndpoint();
        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->base()."/{$endpoint->id}")->assertNoContent();
        $this->assertNull($endpoint->fresh());
    }

    public function test_developers_can_read_masked_endpoints_and_deliveries_but_not_change_them(): void
    {
        $endpoint = $this->slackEndpoint();
        Sanctum::actingAs($this->developer);

        $list = $this->getJson($this->base())->assertOk()->assertJsonPath('data.0.url_masked', 'hooks.slack.com/…EFGH');
        $this->assertStringNotContainsString('T000/B000', $list->getContent());
        $this->getJson($this->base()."/{$endpoint->id}/deliveries")->assertOk();

        $this->postJson($this->base(), ['kind' => 'slack', 'name' => 'x', 'url' => self::SLACK_URL])->assertForbidden();
        $this->patchJson($this->base()."/{$endpoint->id}", ['enabled' => false])->assertForbidden();
        $this->deleteJson($this->base()."/{$endpoint->id}")->assertForbidden();
        $this->postJson($this->base()."/{$endpoint->id}/test")->assertForbidden();
        $this->assertTrue($endpoint->fresh()->enabled);
    }

    public function test_the_demo_account_can_view_alerts_but_not_create_change_delete_or_test_them(): void
    {
        Http::fake();
        $this->seed(DemoSeeder::class);
        $demo = User::where('email', User::DEMO_EMAIL)->sole();
        $project = Project::where('name', DemoSeeder::PROJECT_NAME)->sole();
        $endpoint = $this->slackEndpoint($project);
        Sanctum::actingAs($demo);
        $message = "The shared demo account can't add, change or test alerts. Create your own account to try this.";

        $this->getJson($this->base($project))->assertOk();
        $this->getJson($this->base($project)."/{$endpoint->id}/deliveries")->assertOk();
        $this->postJson($this->base($project), ['kind' => 'slack', 'name' => 'x', 'url' => self::SLACK_URL])->assertForbidden()->assertJsonPath('message', $message);
        $this->patchJson($this->base($project)."/{$endpoint->id}", ['enabled' => false])->assertForbidden()->assertJsonPath('message', $message);
        $this->deleteJson($this->base($project)."/{$endpoint->id}")->assertForbidden();
        $this->postJson($this->base($project)."/{$endpoint->id}/test")->assertForbidden()->assertJsonPath('message', $message);

        Http::assertNothingSent();
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_non_members_get_404_and_ids_from_another_project_get_404(): void
    {
        $stranger = User::factory()->create();
        $other = Project::factory()->withMember($stranger)->create();
        $foreign = $this->slackEndpoint($other);
        $own = $this->slackEndpoint();

        Sanctum::actingAs($stranger);
        $this->getJson($this->base())->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->patchJson($this->base()."/{$own->id}", ['enabled' => false])->assertNotFound();
        $this->getJson($this->base()."/{$own->id}/deliveries")->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->patchJson($this->base()."/{$foreign->id}", ['enabled' => false])->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->deleteJson($this->base()."/{$foreign->id}")->assertNotFound();
        $this->postJson($this->base()."/{$foreign->id}/test")->assertNotFound();
        $this->getJson($this->base()."/{$foreign->id}/deliveries")->assertNotFound();
        $this->assertTrue($foreign->fresh()->enabled);
    }

    public function test_send_test_delivers_a_labelled_message_and_reports_the_outcome(): void
    {
        Http::fakeSequence()->push('ok', 200)->push('no', 410);
        $endpoint = $this->slackEndpoint();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->base()."/{$endpoint->id}/test")
            ->assertOk()
            ->assertJsonPath('data.delivery.status', 'success')
            ->assertJsonPath('data.delivery.test', true)
            ->assertJsonPath('data.endpoint.last_status', 'success')
            ->assertJsonPath('data.message', 'Test message sent.');
        Http::assertSent(fn (Request $request) => str_starts_with($request['blocks'][0]['text']['text'], '[TEST]'));

        $this->postJson($this->base()."/{$endpoint->id}/test")
            ->assertOk()
            ->assertJsonPath('data.delivery.status', 'failed')
            ->assertJsonPath('data.delivery.http_status', 410)
            ->assertJsonPath('data.message', 'The endpoint answered HTTP 410.');

        $this->getJson($this->base()."/{$endpoint->id}/deliveries")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_send_test_is_limited_to_five_a_minute_per_user(): void
    {
        Http::fake();
        $endpoint = $this->slackEndpoint();
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->base()."/{$endpoint->id}/test")->assertOk();
        }
        $this->postJson($this->base()."/{$endpoint->id}/test")->assertTooManyRequests();
        Http::assertSentCount(5);
    }

    public function test_deliveries_list_the_last_fifty_newest_first(): void
    {
        $endpoint = $this->slackEndpoint();
        foreach (range(1, 55) as $i) {
            WebhookDelivery::create([
                'endpoint_id' => $endpoint->id, 'status' => 'success', 'http_status' => 200, 'dispatch_delay_ms' => $i, 'duration_ms' => 10,
                'created_at' => now()->subSeconds(100 - $i)->format('Y-m-d H:i:s.v'),
            ]);
        }
        Sanctum::actingAs($this->developer);

        $this->getJson($this->base()."/{$endpoint->id}/deliveries")->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.dispatch_delay_ms', 55)
            ->assertJsonPath('data.49.dispatch_delay_ms', 6);
    }

    public function test_the_private_address_override_works_locally_and_is_ignored_in_production(): void
    {
        Sanctum::actingAs($this->admin);
        config(['webhooks.allow_private' => true]);
        $local = ['kind' => 'generic', 'name' => 'Local receiver', 'url' => 'http://127.0.0.1:9911/hook'];

        $this->postJson($this->base(), $local)->assertCreated();

        $this->app['env'] = 'production';
        $this->postJson($this->base(), $local)->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_pruning_removes_old_delivery_records(): void
    {
        $endpoint = $this->slackEndpoint();
        $old = WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'status' => 'success', 'created_at' => now()->subDays(31)->format('Y-m-d H:i:s.v')]);
        $recent = WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'status' => 'success', 'created_at' => now()->subDays(2)->format('Y-m-d H:i:s.v')]);

        $this->artisan('pings:prune')
            ->expectsOutput('Deleted 1 webhook delivery record(s) older than 30 day(s).')
            ->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
    }
}

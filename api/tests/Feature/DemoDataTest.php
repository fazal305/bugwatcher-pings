<?php

namespace Tests\Feature;

use App\Demo\DemoProject;
use App\Enums\ProjectRole;
use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private function storeEvent(Project $project, string $message, int $receivedMs): string
    {
        $id = (string) Str::ulid();
        ProcessPing::dispatchSync([
            'id' => $id, 'project_id' => $project->id, 'received_at' => $receivedMs, 'occurred_at' => $receivedMs,
            'message' => $message, 'level' => 'error', 'environment' => 'production',
        ]);

        return $id;
    }

    public function test_the_seeder_adds_labelled_sample_data_to_the_demo_project_only_once(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $project = Project::where('name', DemoProject::NAME)->sole();
        $this->assertSame(6, $project->issues()->count());
        $this->assertSame(13, $project->events()->count());
        $this->assertSame(0, ErrorEvent::where('is_sample', false)->count());
        $this->assertSame(0, Issue::where('is_sample', false)->count());
        $this->assertSame(1, Issue::where('status', 'resolved')->count());
    }

    public function test_sample_events_are_marked_in_the_feed_and_the_detail(): void
    {
        $this->seed(DemoSeeder::class);
        $project = DemoProject::find();
        Sanctum::actingAs(User::where('email', User::DEMO_EMAIL)->sole());

        $feed = $this->getJson("/api/projects/{$project->slug}/events")->assertOk()->json('data');
        $this->assertCount(13, $feed);
        $this->assertSame([true], array_values(array_unique(array_column($feed, 'is_sample'))));

        $detail = $this->getJson("/api/projects/{$project->slug}/events/{$feed[0]['id']}")->assertOk()->json('data');
        $this->assertTrue($detail['is_sample']);
        $this->assertNotEmpty($detail['stacktrace'][0]['context_line']);
        $this->assertSame('[Filtered]', $detail['request']['headers']['Authorization']);
    }

    public function test_new_accounts_and_their_projects_start_empty(): void
    {
        $this->seed(DemoSeeder::class);

        $token = $this->postJson('/api/auth/register', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse', 'age_confirmed' => true,
        ])->assertCreated()->json('token');
        $slug = $this->withToken($token)->postJson('/api/projects', ['name' => 'Shop'])->assertCreated()->json('data.slug');

        $this->withToken($token)->getJson("/api/projects/{$slug}/events")->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Project::where('slug', $slug)->sole()->issues()->count());
    }

    public function test_reset_restores_the_samples_and_keeps_only_recent_visitor_events(): void
    {
        $this->seed(DemoSeeder::class);
        $project = DemoProject::find();
        $demo = User::where('email', User::DEMO_EMAIL)->sole();
        $otherProject = Project::factory()->create();
        $nowMs = (int) floor(microtime(true) * 1000);

        $old = $this->storeEvent($project, 'Old visitor error', $nowMs - 30 * 3600 * 1000);
        $recent = $this->storeEvent($project, 'Recent visitor error', $nowMs - 3600 * 1000);
        $elsewhere = $this->storeEvent($otherProject, 'Old error in another project', $nowMs - 30 * 3600 * 1000);
        $project->issues()->where('is_sample', true)->update(['status' => 'resolved']);
        $project->events()->where('is_sample', true)->limit(3)->get()->each->delete();
        $project->update(['name' => 'Renamed by a visitor']);
        $created = Project::factory()->withMember($demo, ProjectRole::Admin)->create();

        $this->artisan('demo:reset', ['--hours' => 24])->assertSuccessful();

        $project->refresh();
        $this->assertSame(DemoProject::NAME, $project->name);
        $this->assertSame(13, $project->events()->where('is_sample', true)->count());
        $this->assertSame(1, $project->issues()->where('is_sample', true)->where('status', 'resolved')->count());
        $this->assertNull(ErrorEvent::find($old));
        $this->assertNotNull(ErrorEvent::find($recent));
        $this->assertNotNull(ErrorEvent::find($elsewhere));
        $this->assertSame(1, (int) DB::table('issues')->where('id', ErrorEvent::find($recent)->issue_id)->value('event_count'));
        $this->assertSame(0, $project->issues()->doesntHave('events')->count());
        $this->assertNull(Project::find($created->id));
        $this->assertNotNull(Project::find($otherProject->id));
    }

    public function test_the_demo_reset_endpoint_needs_the_cron_secret(): void
    {
        $this->seed(DemoSeeder::class);

        config(['services.cron.secret' => '']);
        $this->postJson('/api/cron/demo-reset', [], ['Authorization' => 'Bearer anything'])->assertUnauthorized();

        config(['services.cron.secret' => 'right-secret']);
        $this->postJson('/api/cron/demo-reset')->assertUnauthorized();
        $this->postJson('/api/cron/demo-reset', [], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $this->postJson('/api/cron/demo-reset', [], ['Authorization' => 'Bearer right-secret'])
            ->assertOk()
            ->assertJsonPath('message', 'OK');
        $this->assertSame(13, DemoProject::find()->events()->count());
    }
}

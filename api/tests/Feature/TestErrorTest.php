<?php

namespace Tests\Feature;

use App\Demo\DemoProject;
use App\Enums\ProjectRole;
use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TestErrorTest extends TestCase
{
    use RefreshDatabase;

    private function path(Project $project): string
    {
        return "/api/projects/{$project->slug}/test-error";
    }

    public function test_an_admin_queues_a_masked_test_ping_on_the_ingest_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin)->create();
        Sanctum::actingAs($admin);

        $id = $this->postJson($this->path($project))->assertStatus(202)->json('id');

        Queue::assertPushedOn('ingest', ProcessPing::class, function (ProcessPing $job) use ($id, $project) {
            $payload = $job->payload;

            return $payload['id'] === $id
                && $payload['project_id'] === $project->id
                && str_starts_with($payload['message'], '[Test] ')
                && $payload['request']['headers']['Authorization'] === '[Filtered]'
                && $payload['request']['body']['password'] === '[Filtered]'
                && str_contains($payload['request']['url'], 'token=[Filtered]')
                && ! str_contains(json_encode($payload), 'test-token-value');
        });
    }

    public function test_the_test_ping_is_stored_like_any_other(): void
    {
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin)->create();
        Sanctum::actingAs($admin);

        $id = $this->postJson($this->path($project))->assertStatus(202)->json('id');
        $this->postJson($this->path($project))->assertStatus(202);

        $event = ErrorEvent::findOrFail($id);
        $this->assertSame($project->id, $event->project_id);
        $this->assertFalse($event->is_sample);
        $this->assertSame('TypeError', $event->exception_type);
        $this->assertSame('bugwatcher-dashboard/test', $event->sdk);
        $this->assertSame(2, $event->issue->fresh()->event_count);
    }

    public function test_the_demo_account_can_fire_test_errors_into_the_demo_project(): void
    {
        Queue::fake();
        $this->seed(DemoSeeder::class);
        Sanctum::actingAs(User::where('email', User::DEMO_EMAIL)->sole());

        $this->postJson($this->path(DemoProject::find()))->assertStatus(202);
        Queue::assertPushed(ProcessPing::class, 1);
    }

    public function test_developers_and_outsiders_cannot_fire_test_errors(): void
    {
        Queue::fake();
        $developer = User::factory()->create();
        $project = Project::factory()->withMember($developer, ProjectRole::Developer)->create();

        Sanctum::actingAs($developer);
        $this->postJson($this->path($project))->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->path($project))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_test_errors_are_limited_to_five_a_minute_per_user(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin)->create();
        Sanctum::actingAs($admin);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->path($project))->assertStatus(202);
        }
        $this->postJson($this->path($project))->assertStatus(429);
        Queue::assertPushed(ProcessPing::class, 5);
    }
}

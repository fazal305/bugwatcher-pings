<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->withMember($this->user, ProjectRole::Developer)->create();
    }

    private function store(Project $project, array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::ulid();
        ProcessPing::dispatchSync(array_replace([
            'id' => $id,
            'project_id' => $project->id,
            'received_at' => 1760000000000,
            'occurred_at' => 1760000000000,
            'message' => 'Boom',
            'level' => 'error',
            'environment' => 'production',
        ], $overrides));

        return $id;
    }

    private function path(?Project $project = null): string
    {
        return '/api/projects/'.($project ?? $this->project)->slug;
    }

    public function test_events_are_listed_newest_first_with_a_cursor(): void
    {
        $ids = [];
        foreach ([1000, 3000, 2000, 3000] as $offset) {
            $ids[] = $this->store($this->project, ['received_at' => 1760000000000 + $offset]);
        }
        $this->store(Project::factory()->create());
        Sanctum::actingAs($this->user);

        $first = $this->getJson($this->path().'/events?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $tied = [$ids[1], $ids[3]];
        rsort($tied);
        $this->assertSame($tied, $first->json('data.*.id'));
        $this->assertSame('1760000003000-'.$tied[1], $first->json('next_cursor'));

        $second = $this->getJson($this->path().'/events?limit=2&before='.$first->json('next_cursor'))->assertOk();
        $this->assertSame([$ids[2], $ids[0]], $second->json('data.*.id'));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_list_items_carry_only_the_feed_fields(): void
    {
        $this->store($this->project, ['request' => ['url' => 'https://shop.test/pay'], 'user' => ['id' => 'customer-42']]);
        Sanctum::actingAs($this->user);

        $item = $this->getJson($this->path().'/events')->assertOk()->json('data.0');

        $this->assertSame([
            'id', 'issue_id', 'issue_title', 'level', 'environment', 'critical', 'message', 'culprit',
            'occurred_at', 'received_at', 'is_new_issue', 'is_regression', 'is_sample', 'event_count',
        ], array_keys($item));
        $this->assertTrue($item['is_new_issue']);
        $this->assertSame('2025-10-09T08:53:20.000Z', $item['received_at']);
    }

    public function test_events_can_be_filtered_by_level_and_environment(): void
    {
        $this->store($this->project, ['level' => 'fatal', 'environment' => 'production']);
        $warning = $this->store($this->project, ['level' => 'warning', 'environment' => 'staging', 'message' => 'Slow']);
        Sanctum::actingAs($this->user);

        $this->getJson($this->path().'/events?level[]=warning&level[]=info')->assertOk()->assertJsonPath('data.*.id', [$warning]);
        $this->getJson($this->path().'/events?environment[]=production')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path().'/events?level[]=critical')->assertJsonValidationErrors('level.0');
        $this->getJson($this->path().'/events?before=yesterday')->assertJsonValidationErrors('before');
    }

    public function test_event_detail_includes_frames_request_and_user_context(): void
    {
        $id = $this->store($this->project, [
            'exception' => ['type' => 'TypeError', 'frames' => [[
                'file' => '/app/cart.js', 'line' => 9, 'function' => 'total',
                'context_line' => 'return cart.total;', 'pre_context' => ['function total(cart) {'], 'post_context' => ['}'],
            ]]],
            'request' => ['method' => 'GET', 'url' => 'https://shop.test/cart?token=[Filtered]', 'headers' => ['Authorization' => '[Filtered]']],
            'user' => ['id' => '42'],
        ]);
        Sanctum::actingAs($this->user);

        $this->getJson($this->path().'/events/'.$id)
            ->assertOk()
            ->assertJsonPath('data.stacktrace.0.context_line', 'return cart.total;')
            ->assertJsonPath('data.stacktrace.0.pre_context', ['function total(cart) {'])
            ->assertJsonPath('data.request.headers.Authorization', '[Filtered]')
            ->assertJsonPath('data.user_context.id', '42')
            ->assertJsonPath('data.issue.event_count', 1);
    }

    public function test_non_members_get_404_for_every_feed_endpoint(): void
    {
        $id = $this->store($this->project);
        $issue = Issue::sole();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->path().'/events')->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->getJson($this->path().'/events/'.$id)->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->getJson($this->path().'/issues')->assertNotFound();
        $this->patchJson($this->path().'/issues/'.$issue->id, ['status' => 'resolved'])->assertNotFound();
        $this->assertSame('open', $issue->fresh()->status);
    }

    public function test_ids_from_another_project_cannot_be_reached_through_your_own(): void
    {
        $theirs = Project::factory()->withMember(User::factory()->create())->create();
        $theirEvent = $this->store($theirs);
        $theirIssue = Issue::where('project_id', $theirs->id)->sole();
        Sanctum::actingAs($this->user);

        $this->getJson($this->path().'/events/'.$theirEvent)->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->patchJson($this->path().'/issues/'.$theirIssue->id, ['status' => 'resolved'])->assertNotFound();
        $this->getJson($this->path().'/events/'.Str::ulid())->assertNotFound();
        $this->assertSame('open', $theirIssue->fresh()->status);
        $this->getJson($this->path().'/events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_issues_are_listed_filtered_and_searched(): void
    {
        $this->store($this->project, ['message' => 'Payment declined']);
        $this->store($this->project, ['message' => 'Cart 100% broken']);
        Issue::where('title', 'Payment declined')->update(['status' => 'resolved']);
        Sanctum::actingAs($this->user);

        $this->getJson($this->path().'/issues')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->path().'/issues?status=resolved')->assertOk()->assertJsonPath('data.*.title', ['Payment declined']);
        $this->getJson($this->path().'/issues?q=PAYMENT')->assertOk()->assertJsonPath('data.*.title', ['Payment declined']);
        $this->getJson($this->path().'/issues?q=100%25')->assertOk()->assertJsonPath('data.*.title', ['Cart 100% broken']);
        $this->getJson($this->path().'/issues?q=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path().'/issues?status=closed')->assertJsonValidationErrors('status');
    }

    public function test_developers_and_admins_can_resolve_and_reopen_issues(): void
    {
        $this->store($this->project);
        $issue = Issue::sole();
        $admin = User::factory()->create();
        $this->project->members()->create(['user_id' => $admin->id, 'role' => ProjectRole::Admin]);

        Sanctum::actingAs($this->user);
        $this->patchJson($this->path().'/issues/'.$issue->id, ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');

        Sanctum::actingAs($admin);
        $this->patchJson($this->path().'/issues/'.$issue->id, ['status' => 'open'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->patchJson($this->path().'/issues/'.$issue->id, ['status' => 'ignored'])->assertJsonValidationErrors('status');
    }

    public function test_feed_endpoints_require_authentication(): void
    {
        $id = $this->store($this->project);

        $this->getJson($this->path().'/events')->assertUnauthorized();
        $this->getJson($this->path().'/events/'.$id)->assertUnauthorized();
        $this->assertSame(1, ErrorEvent::count());
    }
}

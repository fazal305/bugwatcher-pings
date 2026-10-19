<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = "The shared demo account can't change members or rotate keys. Create your own account to try this.";

    private User $demo;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->demo = User::where('email', User::DEMO_EMAIL)->sole();
        $this->project = Project::where('name', DemoSeeder::PROJECT_NAME)->sole();
    }

    public function test_the_demo_account_cannot_invite_members(): void
    {
        Sanctum::actingAs($this->demo);

        $this->postJson("/api/projects/{$this->project->slug}/invitations", ['email' => 'visitor@example.com', 'role' => 'admin'])
            ->assertForbidden()
            ->assertJsonPath('message', self::MESSAGE);

        $this->assertDatabaseCount('project_invitations', 0);
    }

    public function test_the_demo_account_cannot_change_or_remove_members(): void
    {
        $member = $this->project->members()->create(['user_id' => User::factory()->create()->id, 'role' => ProjectRole::Developer]);
        $demoMembership = $this->project->membershipFor($this->demo);
        Sanctum::actingAs($this->demo);

        $this->patchJson("/api/projects/{$this->project->slug}/members/{$member->id}", ['role' => 'admin'])->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->deleteJson("/api/projects/{$this->project->slug}/members/{$member->id}")->assertForbidden();
        $this->deleteJson("/api/projects/{$this->project->slug}/members/{$demoMembership->id}")->assertForbidden();

        $this->assertSame(ProjectRole::Developer, $member->fresh()->role);
        $this->assertNotNull($demoMembership->fresh());
    }

    public function test_the_demo_account_cannot_rotate_the_key(): void
    {
        $key = $this->project->public_key;
        Sanctum::actingAs($this->demo);

        $this->postJson("/api/projects/{$this->project->slug}/rotate-key")->assertForbidden()->assertJsonPath('message', self::MESSAGE);

        $this->assertSame($key, $this->project->fresh()->public_key);
    }

    public function test_the_demo_account_can_still_view_the_project_and_its_members(): void
    {
        Sanctum::actingAs($this->demo);

        $this->getJson("/api/projects/{$this->project->slug}")->assertOk()->assertJsonPath('data.role', 'admin');
        $this->getJson("/api/projects/{$this->project->slug}/members")->assertOk();
    }

    public function test_regular_admins_are_unaffected(): void
    {
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin)->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$project->slug}/rotate-key")->assertOk();
        $this->postJson("/api/projects/{$project->slug}/invitations", ['email' => 'someone@example.com', 'role' => 'developer'])->assertCreated();
    }
}

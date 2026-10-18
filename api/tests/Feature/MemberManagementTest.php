<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->project = Project::factory()->withMember($this->admin)->create();
    }

    private function url(string $path = ''): string
    {
        return "/api/projects/{$this->project->slug}/members{$path}";
    }

    public function test_adding_a_member_gives_the_same_reply_whether_or_not_the_account_exists(): void
    {
        $dev = User::factory()->create(['email' => 'dev@example.com']);
        Sanctum::actingAs($this->admin);

        $existing = $this->postJson($this->url(), ['email' => 'DEV@example.com', 'role' => 'developer'])->assertStatus(202)->json();
        $unknown = $this->postJson($this->url(), ['email' => 'ghost@example.com', 'role' => 'developer'])->assertStatus(202)->json();

        $this->assertSame($existing, $unknown);
        $this->assertDatabaseHas('project_members', ['project_id' => $this->project->id, 'user_id' => $dev->id, 'role' => 'developer']);
        $this->assertDatabaseCount('project_members', 2);
    }

    public function test_add_member_is_validated(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['email' => 'nope', 'role' => 'owner'])->assertJsonValidationErrors(['email', 'role']);
    }

    public function test_only_admins_see_member_emails(): void
    {
        $dev = User::factory()->create(['name' => 'Dev', 'email' => 'dev@example.com']);
        $this->project->members()->create(['user_id' => $dev->id, 'role' => ProjectRole::Developer]);

        Sanctum::actingAs($this->admin);
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.email', 'admin@example.com')
            ->assertJsonPath('data.0.is_you', true)
            ->assertJsonPath('data.1.email', 'dev@example.com');

        Sanctum::actingAs($dev);
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.name', 'Admin');
        $this->assertStringNotContainsString('@example.com', $response->getContent());
    }

    public function test_admin_can_change_roles_and_remove_members(): void
    {
        $dev = User::factory()->create();
        $membership = $this->project->members()->create(['user_id' => $dev->id, 'role' => ProjectRole::Developer]);
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->url("/{$membership->id}"), ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
        $this->deleteJson($this->url("/{$membership->id}"))->assertNoContent();

        $this->assertDatabaseMissing('project_members', ['id' => $membership->id]);
    }

    public function test_developer_cannot_change_roles_or_remove_members(): void
    {
        $dev = User::factory()->create();
        $this->project->members()->create(['user_id' => $dev->id, 'role' => ProjectRole::Developer]);
        $adminMembership = $this->project->members()->where('user_id', $this->admin->id)->first();
        Sanctum::actingAs($dev);

        $this->patchJson($this->url("/{$adminMembership->id}"), ['role' => 'developer'])->assertForbidden();
        $this->deleteJson($this->url("/{$adminMembership->id}"))->assertForbidden();
        $this->assertSame(ProjectRole::Admin, $adminMembership->fresh()->role);
    }

    public function test_the_last_admin_cannot_be_demoted_or_removed(): void
    {
        $membership = $this->project->members()->where('user_id', $this->admin->id)->first();
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->url("/{$membership->id}"), ['role' => 'developer'])->assertJsonValidationErrors('role');
        $this->deleteJson($this->url("/{$membership->id}"))->assertJsonValidationErrors('member');

        $this->assertSame(ProjectRole::Admin, $membership->fresh()->role);
    }

    public function test_an_admin_can_step_down_once_another_admin_exists(): void
    {
        $other = User::factory()->create();
        $this->project->members()->create(['user_id' => $other->id, 'role' => ProjectRole::Admin]);
        $membership = $this->project->members()->where('user_id', $this->admin->id)->first();
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->url("/{$membership->id}"), ['role' => 'developer'])->assertOk()->assertJsonPath('data.role', 'developer');
    }

    public function test_member_ids_from_another_project_cannot_be_used(): void
    {
        $otherProject = Project::factory()->withMember(User::factory()->create())->create();
        $victimMembership = $otherProject->members()->create(['user_id' => User::factory()->create()->id, 'role' => ProjectRole::Developer]);
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->url("/{$victimMembership->id}"), ['role' => 'admin'])->assertNotFound();
        $this->deleteJson($this->url("/{$victimMembership->id}"))->assertNotFound();

        $this->assertSame(ProjectRole::Developer, $victimMembership->fresh()->role);
    }

    public function test_a_user_cannot_reach_another_users_project_by_tampering_with_the_slug(): void
    {
        $intruder = User::factory()->create();
        $intruderProject = Project::factory()->withMember($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->getJson("/api/projects/{$intruderProject->slug}")->assertOk();
        $this->getJson("/api/projects/{$this->project->slug}")->assertNotFound();
        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url(), ['email' => $intruder->email, 'role' => 'admin'])->assertNotFound();

        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id, 'user_id' => $intruder->id]);
    }
}

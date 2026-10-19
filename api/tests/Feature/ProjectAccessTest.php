<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->postJson('/api/projects', ['name' => 'Shop'])->assertUnauthorized();
    }

    public function test_creator_becomes_admin_and_gets_a_public_key(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', ['name' => 'My Shop'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'My Shop')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonMissingPath('data.id');

        $this->assertMatchesRegularExpression('/^bwp_pk_[A-Za-z0-9]{32,}$/', $response->json('data.public_key'));
        $this->assertStringStartsWith('my-shop-', $response->json('data.slug'));
        $this->assertDatabaseHas('project_members', ['user_id' => $user->id, 'role' => 'admin']);
    }

    public function test_project_name_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/projects', ['name' => '   '])->assertJsonValidationErrors('name');
        $this->postJson('/api/projects', ['name' => str_repeat('a', 81)])->assertJsonValidationErrors('name');
    }

    public function test_index_lists_only_projects_the_user_belongs_to(): void
    {
        $user = User::factory()->create();
        Project::factory()->withMember($user)->create(['name' => 'Mine']);
        Project::factory()->withMember($user, ProjectRole::Developer)->create(['name' => 'Shared']);
        Project::factory()->withMember(User::factory()->create())->create(['name' => 'Theirs']);
        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonPath('data.1.role', 'developer')
            ->assertJsonMissingPath('data.0.public_key');
    }

    public function test_non_member_gets_the_same_404_as_a_missing_project(): void
    {
        $project = Project::factory()->withMember(User::factory()->create())->create();
        Sanctum::actingAs(User::factory()->create());

        $missing = $this->getJson('/api/projects/no-such-project')->assertNotFound()->json();
        $this->assertSame(['message' => 'Not Found.'], $missing);

        foreach ([['get', ''], ['patch', ''], ['post', '/rotate-key'], ['get', '/members'], ['post', '/invitations'], ['get', '/invitations']] as [$method, $path]) {
            $this->json($method, "/api/projects/{$project->slug}{$path}", ['name' => 'x', 'email' => 'a@b.co', 'role' => 'admin'])
                ->assertNotFound()
                ->assertExactJson($missing);
        }

        // Invalid input still answers 404, so validation errors can't reveal that the project exists.
        $this->postJson("/api/projects/{$project->slug}/invitations", [])->assertNotFound();
    }

    public function test_developer_can_view_project_and_key_but_not_admin_actions(): void
    {
        $developer = User::factory()->create();
        $project = Project::factory()
            ->withMember(User::factory()->create())
            ->withMember($developer, ProjectRole::Developer)
            ->create(['name' => 'Shop']);
        Sanctum::actingAs($developer);

        $this->getJson("/api/projects/{$project->slug}")
            ->assertOk()
            ->assertJsonPath('data.public_key', $project->public_key)
            ->assertJsonPath('data.role', 'developer');

        $this->patchJson("/api/projects/{$project->slug}", ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only project admins can do that.');
        $this->postJson("/api/projects/{$project->slug}/rotate-key")->assertForbidden();
        $this->postJson("/api/projects/{$project->slug}/invitations", ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();

        $fresh = $project->fresh();
        $this->assertSame('Shop', $fresh->name);
        $this->assertSame($project->public_key, $fresh->public_key);
        $this->assertSame(2, $fresh->members()->count());
    }

    public function test_admin_can_rename_and_rotate_the_key(): void
    {
        $admin = User::factory()->create();
        $project = Project::factory()->withMember($admin)->create(['name' => 'Shop']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/projects/{$project->slug}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.slug', $project->slug);

        $newKey = $this->postJson("/api/projects/{$project->slug}/rotate-key")->assertOk()->json('data.public_key');
        $this->assertNotSame($project->public_key, $newKey);
        $this->assertSame($newKey, $project->fresh()->public_key);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID = 'This invitation link is invalid or has expired.';

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->project = Project::factory()->withMember($this->admin)->create(['name' => 'Storefront']);
    }

    private function url(string $path = ''): string
    {
        return "/api/projects/{$this->project->slug}/invitations{$path}";
    }

    /** Invites as the admin and returns the raw token from the link. */
    private function invite(string $email, string $role = 'developer'): string
    {
        Sanctum::actingAs($this->admin);
        $url = $this->postJson($this->url(), ['email' => $email, 'role' => $role])->assertCreated()->json('invite_url');

        return substr($url, strrpos($url, '/') + 1);
    }

    /** @return array<string, mixed> The response with the parts that differ per invitation (id, email, expiry, token) blanked. */
    private function shape(array $json): array
    {
        $this->assertMatchesRegularExpression('#^http://localhost:5473/invite/[A-Za-z0-9]{48}$#', $json['invite_url']);
        $json['invite_url'] = 'URL';
        $json['invitation']['id'] = 'ID';
        $json['invitation']['email'] = 'EMAIL';
        $json['invitation']['expires_at'] = 'EXPIRES';

        return $json;
    }

    public function test_inviting_answers_identically_for_unknown_emails_existing_accounts_and_current_members(): void
    {
        User::factory()->create(['email' => 'outsider@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->project->members()->create(['user_id' => $member->id, 'role' => ProjectRole::Developer]);
        Sanctum::actingAs($this->admin);

        $responses = collect(['ghost@example.com', 'OUTSIDER@example.com', 'member@example.com'])
            ->map(fn ($email) => $this->postJson($this->url(), ['email' => $email, 'role' => 'developer']));

        foreach ($responses as $response) {
            $response->assertCreated()->assertExactJsonStructure(['invitation' => ['id', 'email', 'role', 'expires_at'], 'invite_url']);
        }
        $shapes = $responses->map(fn ($response) => $this->shape($response->json()));
        $this->assertSame($shapes[0], $shapes[1]);
        $this->assertSame($shapes[0], $shapes[2]);
        $this->assertSame('outsider@example.com', $responses[1]->json('invitation.email'));

        // Nobody was added; only invitations exist.
        $this->assertDatabaseCount('project_members', 2);
        $this->assertDatabaseCount('project_invitations', 3);
    }

    public function test_inviting_never_looks_up_users_by_email(): void
    {
        User::factory()->create(['email' => 'outsider@example.com']);
        Sanctum::actingAs($this->admin);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->postJson($this->url(), ['email' => 'outsider@example.com', 'role' => 'admin'])->assertCreated();

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertFalse(str_contains($sql, '"users"') && str_contains($sql, 'email'), "Invite queried users by email: {$sql}");
        }
    }

    public function test_the_token_is_stored_hashed_and_the_invitation_expires_in_seven_days(): void
    {
        $this->freezeSecond();
        $token = $this->invite('new@example.com');

        $invitation = ProjectInvitation::sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertGreaterThanOrEqual(40, strlen($token));
        $this->assertTrue($invitation->expires_at->equalTo(now()->addDays(7)));
        $this->assertSame($this->admin->id, $invitation->invited_by);
        foreach (DB::table('project_invitations')->get() as $row) {
            $this->assertStringNotContainsString($token, json_encode($row));
        }
    }

    public function test_emails_are_trimmed_and_lowercased_and_the_invite_is_validated(): void
    {
        $this->invite('  New.Person@Example.COM ');
        $this->assertSame('new.person@example.com', ProjectInvitation::sole()->email);

        $this->postJson($this->url(), ['email' => 'nope', 'role' => 'owner'])->assertJsonValidationErrors(['email', 'role']);
    }

    public function test_re_inviting_replaces_the_pending_invitation_and_the_old_link_stops_working(): void
    {
        $old = $this->invite('new@example.com', 'developer');
        $new = $this->invite('NEW@example.com', 'admin');

        $this->assertDatabaseCount('project_invitations', 1);
        $this->getJson("/api/invitations/{$old}")->assertNotFound()->assertExactJson(['message' => self::INVALID]);
        $this->getJson("/api/invitations/{$new}")->assertOk()->assertJsonPath('role', 'admin');
    }

    public function test_the_summary_shows_only_the_project_name_role_and_expiry(): void
    {
        $token = $this->invite('new@example.com', 'admin');
        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/invitations/{$token}")->assertOk()->assertExactJsonStructure(['project_name', 'role', 'expires_at']);

        $response->assertJsonPath('project_name', 'Storefront')->assertJsonPath('role', 'admin');
        $this->assertStringNotContainsString('new@example.com', $response->getContent());
    }

    public function test_the_summary_gives_the_same_404_for_unknown_expired_and_used_links(): void
    {
        $expired = $this->invite('late@example.com');
        ProjectInvitation::where('email', 'late@example.com')->update(['expires_at' => now()->subMinute()]);
        $used = $this->invite('used@example.com');
        ProjectInvitation::where('email', 'used@example.com')->update(['accepted_at' => now()]);

        foreach (['bogus', str_repeat('a', 48), $expired, $used] as $token) {
            $this->getJson("/api/invitations/{$token}")->assertNotFound()->assertExactJson(['message' => self::INVALID]);
        }
    }

    public function test_accepting_adds_the_member_once_and_the_link_is_single_use(): void
    {
        $token = $this->invite('New@Example.com', 'admin');
        $invitee = User::factory()->create(['email' => 'new@example.com']);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/invitations/{$token}/accept")
            ->assertOk()
            ->assertExactJson(['project' => ['slug' => $this->project->slug, 'name' => 'Storefront']]);

        $this->assertDatabaseHas('project_members', ['project_id' => $this->project->id, 'user_id' => $invitee->id, 'role' => 'admin']);
        $this->assertNotNull(ProjectInvitation::sole()->accepted_at);
        $this->getJson("/api/projects/{$this->project->slug}")->assertOk();

        $this->postJson("/api/invitations/{$token}/accept")->assertNotFound()->assertJsonPath('message', self::INVALID);
        $this->getJson("/api/invitations/{$token}")->assertNotFound();
        $this->assertDatabaseCount('project_members', 2);
    }

    public function test_an_existing_member_who_accepts_keeps_their_role(): void
    {
        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->project->members()->create(['user_id' => $member->id, 'role' => ProjectRole::Developer]);
        $token = $this->invite('member@example.com', 'admin');
        Sanctum::actingAs($member);

        $this->postJson("/api/invitations/{$token}/accept")->assertOk();

        $this->assertSame(ProjectRole::Developer, $this->project->membershipFor($member)->role);
        $this->assertNotNull(ProjectInvitation::sole()->accepted_at);
    }

    public function test_accepting_with_a_different_email_is_refused(): void
    {
        $token = $this->invite('new@example.com');
        $other = User::factory()->create(['email' => 'other@example.com']);
        Sanctum::actingAs($other);

        $this->postJson("/api/invitations/{$token}/accept")
            ->assertForbidden()
            ->assertJsonPath('message', 'This invitation was sent to a different email address. Sign in with that address to accept it.');

        $this->assertDatabaseMissing('project_members', ['user_id' => $other->id]);
        $this->assertNull(ProjectInvitation::sole()->accepted_at);
    }

    public function test_expired_and_unknown_links_cannot_be_accepted(): void
    {
        $token = $this->invite('new@example.com');
        $invitee = User::factory()->create(['email' => 'new@example.com']);
        $this->travel(ProjectInvitation::LIFETIME_DAYS)->days();
        $this->travel(1)->minutes();
        Sanctum::actingAs($invitee);

        $this->postJson("/api/invitations/{$token}/accept")->assertNotFound()->assertExactJson(['message' => self::INVALID]);
        $this->postJson('/api/invitations/bogus/accept')->assertNotFound()->assertExactJson(['message' => self::INVALID]);
        $this->assertDatabaseMissing('project_members', ['user_id' => $invitee->id]);
    }

    public function test_accepting_needs_a_signed_in_user(): void
    {
        $token = $this->invite('new@example.com');
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/invitations/{$token}/accept")->assertUnauthorized();
    }

    public function test_a_revoked_link_stops_working(): void
    {
        $token = $this->invite('new@example.com');
        $id = ProjectInvitation::sole()->id;

        $this->deleteJson($this->url("/{$id}"))->assertNoContent();

        $this->assertDatabaseCount('project_invitations', 0);
        $this->getJson("/api/invitations/{$token}")->assertNotFound();
    }

    public function test_admins_list_pending_unexpired_invitations_only(): void
    {
        $this->invite('pending@example.com', 'admin');
        $this->invite('late@example.com');
        $this->invite('used@example.com');
        ProjectInvitation::where('email', 'late@example.com')->update(['expires_at' => now()->subMinute()]);
        ProjectInvitation::where('email', 'used@example.com')->update(['accepted_at' => now()]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertExactJsonStructure(['data' => [['id', 'email', 'role', 'expires_at', 'invited_by']]])
            ->assertJsonPath('data.0.email', 'pending@example.com')
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.invited_by', 'Admin');
    }

    public function test_developers_get_403_and_non_members_404_on_invitation_management(): void
    {
        $this->invite('new@example.com');
        $id = ProjectInvitation::sole()->id;
        $dev = User::factory()->create();
        $this->project->members()->create(['user_id' => $dev->id, 'role' => ProjectRole::Developer]);
        $outsider = User::factory()->create();

        Sanctum::actingAs($dev);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->deleteJson($this->url("/{$id}"))->assertForbidden();

        Sanctum::actingAs($outsider);
        $this->getJson($this->url())->assertNotFound()->assertExactJson(['message' => 'Not Found.']);
        $this->postJson($this->url(), ['email' => 'x@example.com', 'role' => 'admin'])->assertNotFound();
        $this->deleteJson($this->url("/{$id}"))->assertNotFound();

        $this->assertDatabaseCount('project_invitations', 1);
    }

    public function test_invitation_ids_from_another_project_cannot_be_revoked(): void
    {
        $otherAdmin = User::factory()->create();
        $otherProject = Project::factory()->withMember($otherAdmin)->create();
        $victim = $otherProject->invitations()->create([
            'email' => 'victim@example.com',
            'role' => 'developer',
            'token_hash' => ProjectInvitation::hashToken('x'),
            'invited_by' => $otherAdmin->id,
            'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->url("/{$victim->id}"))->assertNotFound()->assertExactJson(['message' => 'Not Found.']);

        $this->assertNotNull($victim->fresh());
    }

    public function test_the_old_direct_add_endpoint_is_gone(): void
    {
        $dev = User::factory()->create(['email' => 'dev@example.com']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/projects/{$this->project->slug}/members", ['email' => 'dev@example.com', 'role' => 'developer'])->assertStatus(405);

        $this->assertDatabaseMissing('project_members', ['user_id' => $dev->id]);
    }

    public function test_the_demo_account_cannot_invite_list_or_accept(): void
    {
        $this->seed(DemoSeeder::class);
        $demo = User::where('email', User::DEMO_EMAIL)->sole();
        $demoProject = Project::where('name', DemoSeeder::PROJECT_NAME)->sole();
        // An admin elsewhere invites the shared demo address: accepting would let every demo visitor in.
        $token = $this->invite(User::DEMO_EMAIL);
        Sanctum::actingAs($demo);

        $this->postJson("/api/projects/{$demoProject->slug}/invitations", ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->getJson("/api/projects/{$demoProject->slug}/invitations")->assertForbidden();
        $this->postJson("/api/invitations/{$token}/accept")
            ->assertForbidden()
            ->assertJsonPath('message', "The shared demo account can't accept invitations. Create your own account to accept this one.");

        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id, 'user_id' => $demo->id]);
        $this->assertSame(1, ProjectInvitation::whereNull('accepted_at')->where('project_id', $this->project->id)->count());
    }

    public function test_inviting_is_rate_limited_per_minute_and_per_hour(): void
    {
        Sanctum::actingAs($this->admin);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url(), ['email' => "p{$i}@example.com", 'role' => 'developer'])->assertCreated();
        }
        $this->postJson($this->url(), ['email' => 'p5@example.com', 'role' => 'developer'])->assertTooManyRequests();

        $count = 5;
        for ($minute = 1; $minute <= 3; $minute++) {
            $this->travel(61)->seconds();
            for ($i = 0; $i < 5; $i++, $count++) {
                $this->postJson($this->url(), ['email' => "q{$count}@example.com", 'role' => 'developer'])->assertCreated();
            }
        }
        $this->travel(61)->seconds();
        $this->postJson($this->url(), ['email' => 'last@example.com', 'role' => 'developer'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many attempts. Please wait a minute and try again.');
    }

    public function test_link_lookups_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/invitations/guess{$i}")->assertNotFound();
        }
        $this->getJson('/api/invitations/guess-more')->assertTooManyRequests();
    }

    public function test_accepting_is_rate_limited_per_user(): void
    {
        Sanctum::actingAs(User::factory()->create());
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/invitations/guess{$i}/accept")->assertNotFound();
        }
        $this->postJson('/api/invitations/guess-more/accept')->assertTooManyRequests();
    }
}

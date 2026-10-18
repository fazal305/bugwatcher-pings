<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'age_confirmed' => true,
        ], $overrides);
    }

    public function test_register_returns_a_token_and_profile(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registration(['email' => ' Ada@Example.com ']))
            ->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonMissingPath('user.id');

        $this->withToken($response->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('user.name', 'Ada Lovelace');
    }

    public function test_register_without_age_confirmation_is_rejected_with_a_field_error(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['age_confirmed' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['age_confirmed' => 'You must be 16 or older to create an account.']);

        $this->postJson('/api/auth/register', $this->registration(['age_confirmed' => null]))
            ->assertJsonValidationErrors('age_confirmed');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_validates_fields(): void
    {
        $this->postJson('/api/auth/register', ['email' => 'not-an-email', 'password' => 'short', 'age_confirmed' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_login_failure_is_the_same_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123'])->assertUnprocessable();
        $wrong = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'whatever-123'])->assertUnprocessable();

        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertSame('The email or password is incorrect.', $wrong->json('errors.email.0'));
    }

    public function test_login_and_logout(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $token = $this->postJson('/api/auth/login', ['email' => 'ADA@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_tokens_expire_after_the_configured_lifetime(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = User::factory()->create()->issueToken();

        $this->travel(61)->minutes();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_per_email_with_a_friendly_message(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'Ada@Example.com', 'password' => 'wrong-password'])
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too many attempts. Please wait a minute and try again.']);

        $this->postJson('/api/auth/login', ['email' => 'other@example.com', 'password' => 'wrong-password'])->assertUnprocessable();
    }

    public function test_login_is_rate_limited_per_ip_across_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', ['email' => "user{$i}@example.com", 'password' => 'wrong-password']);
        }

        $this->postJson('/api/auth/login', ['email' => 'fresh@example.com', 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', $this->registration(['email' => "user{$i}@example.com"]));
        }

        $this->postJson('/api/auth/register', $this->registration(['email' => 'late@example.com']))->assertTooManyRequests();
    }

    public function test_demo_login_signs_into_the_seeded_demo_account(): void
    {
        config(['services.demo.login_enabled' => true]);
        $this->seed(DemoSeeder::class);

        $token = $this->postJson('/api/auth/demo')->assertOk()->assertJsonPath('user.is_demo', true)->json('token');

        $this->withToken($token)->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('data.0.name', DemoSeeder::PROJECT_NAME)
            ->assertJsonPath('data.0.role', 'admin');
    }

    public function test_demo_login_is_disabled_when_the_flag_is_off(): void
    {
        config(['services.demo.login_enabled' => false]);
        $this->seed(DemoSeeder::class);

        $this->postJson('/api/auth/demo')->assertNotFound()->assertJsonMissingPath('token');
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_members', 1);
    }

    public function test_demo_login_is_rate_limited(): void
    {
        config(['services.demo.login_enabled' => true]);
        $this->seed(DemoSeeder::class);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/demo')->assertOk();
        }

        $this->postJson('/api/auth/demo')->assertTooManyRequests();
    }
}

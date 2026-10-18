<?php

namespace Tests\Feature;

use App\Console\Commands\EnableRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnableRowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enables_rls_with_a_deny_policy_on_postgres_and_is_a_no_op_elsewhere(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->artisan('db:enable-rls')->expectsOutput('Not a PostgreSQL connection; nothing to do.')->assertSuccessful();

            return;
        }

        $this->artisan('db:enable-rls')->assertSuccessful();
        $this->artisan('db:enable-rls')->assertSuccessful();

        $tables = DB::select(
            "SELECT c.relname, c.relrowsecurity,
                    (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = ? AND NOT p.polpermissive) AS policies
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind = 'r'",
            [EnableRowLevelSecurity::POLICY],
        );

        $this->assertNotEmpty($tables);
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity, "{$table->relname} has RLS off");
            $this->assertSame(1, (int) $table->policies, "{$table->relname} should have exactly one deny policy");
        }

        // The owning app role is unaffected by RLS, so the app keeps working.
        $this->assertSame(0, DB::table('projects')->count());
    }
}

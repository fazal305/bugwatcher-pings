<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('db:enable-rls')]
#[Description('PostgreSQL only: switch on row level security, with a deny policy, for every table in the app schema')]
class EnableRowLevelSecurity extends Command
{
    public const POLICY = 'deny_non_owner_roles';

    /**
     * The app's own role owns these tables and RLS is not forced, so the app is not affected. Every other role
     * (such as a hosted Postgres provider's API roles) is denied, even if it is later granted table access. The
     * restrictive policy states that denial explicitly and keeps holding if a permissive policy is ever added.
     */
    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->info('Not a PostgreSQL connection; nothing to do.');

            return self::SUCCESS;
        }

        $tables = DB::select(
            "SELECT c.relname, c.relrowsecurity,
                    EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = ?) AS has_policy
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')",
            [self::POLICY],
        );

        $enabled = 0;
        foreach ($tables as $table) {
            $name = DB::getQueryGrammar()->wrapTable($table->relname);

            if (! $table->relrowsecurity) {
                DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
                $enabled++;
            }
            if (! $table->has_policy) {
                DB::statement('CREATE POLICY '.self::POLICY." ON {$name} AS RESTRICTIVE FOR ALL TO PUBLIC USING (false) WITH CHECK (false)");
            }
        }

        $this->info("Row level security enabled on {$enabled} table(s); ".count($tables).' table(s) checked.');

        return self::SUCCESS;
    }
}

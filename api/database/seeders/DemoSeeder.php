<?php

namespace Database\Seeders;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public const PROJECT_NAME = 'Demo Storefront (sample data)';

    // Safe to run repeatedly. The password is random and never shown: the demo is only reachable through POST /api/auth/demo.
    public function run(): void
    {
        DB::transaction(function () {
            $user = User::firstOrCreate(
                ['email' => User::DEMO_EMAIL],
                ['name' => 'Demo', 'password' => Str::random(64)],
            );

            if (! $user->memberships()->whereHas('project', fn ($q) => $q->where('name', self::PROJECT_NAME))->exists()) {
                $project = Project::create(['name' => self::PROJECT_NAME]);
                $project->members()->create(['user_id' => $user->id, 'role' => ProjectRole::Admin]);
            }
        });
    }
}

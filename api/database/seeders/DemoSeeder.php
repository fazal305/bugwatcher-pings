<?php

namespace Database\Seeders;

use App\Demo\DemoProject;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public const PROJECT_NAME = DemoProject::NAME;

    // Safe to run repeatedly: sample data is only added when the demo project has none.
    public function run(): void
    {
        DemoProject::ensure();
    }
}

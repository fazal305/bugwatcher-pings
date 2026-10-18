<?php

namespace App\Console\Commands;

use App\Demo\DemoProject;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset {--hours= : Keep visitors\' events from this many hours (default DEMO_EVENT_TTL_HOURS)}')]
#[Description('Reset the shared demo project to its sample data and delete older visitor events')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        $hours = max(0, (int) ($this->option('hours') ?? config('services.demo.event_ttl_hours')));
        $result = DemoProject::reset($hours);

        $this->info("Demo reset: deleted {$result['events']} event(s) (sample data and visitor events older than {$hours} hour(s)) and {$result['projects']} extra project(s).");

        return self::SUCCESS;
    }
}

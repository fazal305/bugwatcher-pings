<?php

namespace Tests\Feature;

use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneAndCronTest extends TestCase
{
    use RefreshDatabase;

    private function storeEventReceived(string $receivedAt): string
    {
        $id = (string) Str::ulid();
        ProcessPing::dispatchSync([
            'id' => $id,
            'project_id' => Project::factory()->create()->id,
            'received_at' => 1760000000000,
            'occurred_at' => 1760000000000,
            'message' => 'Boom',
            'level' => 'error',
            'environment' => 'production',
        ]);
        DB::table('events')->where('id', $id)->update(['received_at' => $receivedAt]);

        return $id;
    }

    public function test_prune_deletes_only_events_older_than_the_retention_period(): void
    {
        $this->freezeTime();
        $old = [
            $this->storeEventReceived(now()->subDays(31)->format('Y-m-d H:i:s.v')),
            $this->storeEventReceived(now()->subDays(45)->format('Y-m-d H:i:s.v')),
            $this->storeEventReceived(now()->subDays(400)->format('Y-m-d H:i:s.v')),
        ];
        $recent = [
            $this->storeEventReceived(now()->subDays(29)->format('Y-m-d H:i:s.v')),
            $this->storeEventReceived(now()->subMinute()->format('Y-m-d H:i:s.v')),
        ];

        $this->artisan('pings:prune', ['--batch' => 2])
            ->expectsOutput('Deleted 3 event(s) older than 30 day(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing($recent, ErrorEvent::pluck('id')->all());
        $this->assertSame(0, ErrorEvent::whereIn('id', $old)->count());
    }

    public function test_cron_endpoint_rejects_a_missing_or_wrong_secret(): void
    {
        config(['services.cron.secret' => '']);
        $this->postJson('/api/cron/run', [], ['Authorization' => 'Bearer anything'])->assertUnauthorized();

        config(['services.cron.secret' => 'right-secret']);
        $this->postJson('/api/cron/run')->assertUnauthorized();
        $this->postJson('/api/cron/run', [], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function test_cron_endpoint_drains_the_spool_and_prunes(): void
    {
        $this->requireRedis();
        config(['services.cron.secret' => 'right-secret']);

        $this->postJson('/api/cron/run', [], ['Authorization' => 'Bearer right-secret'])
            ->assertOk()
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('prune', 'Deleted 0 event(s) older than 30 day(s).'.PHP_EOL.'Deleted 0 webhook delivery record(s) older than 30 day(s).');
    }
}

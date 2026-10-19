<?php

namespace Tests\Feature;

use App\Events\PingProcessed;
use App\Jobs\ProcessPing;
use App\Models\ErrorEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Pings\PingProcessor;
use App\Pings\PingSpool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ProcessPingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
    }

    // Only the spool tests touch Redis; storing a ping doesn't.
    private function spool(): PingSpool
    {
        $this->requireRedis();
        $spool = app(PingSpool::class);
        $spool->clear();
        $this->beforeApplicationDestroyed(fn () => $spool->clear());

        return $spool;
    }

    private function payload(array $overrides = [], ?array $frames = null): array
    {
        return array_replace([
            'id' => (string) Str::ulid(),
            'project_id' => $this->project->id,
            'received_at' => 1760000001000,
            'occurred_at' => 1760000000500,
            'message' => "Cannot read properties of undefined (reading 'total')",
            'level' => 'error',
            'environment' => 'production',
            'exception' => [
                'type' => 'TypeError',
                'frames' => $frames ?? [
                    ['file' => 'https://shop.test/node_modules/react-dom/index.js', 'line' => 900, 'function' => 'commitRoot'],
                    ['file' => 'https://shop.test/assets/index-3fa9c1d2.js?v=2', 'line' => 42, 'function' => 'Cart.total', 'context_line' => 'return cart.total;'],
                ],
            ],
            'request' => ['method' => 'GET', 'url' => 'https://shop.test/cart'],
            'user' => ['id' => '42'],
            'release' => '1.2.0',
            'sdk' => ['name' => 'bugwatcher-js', 'version' => '0.1.0'],
        ], $overrides);
    }

    public function test_a_ping_creates_an_issue_and_an_event(): void
    {
        Event::fake([PingProcessed::class]);
        $payload = $this->payload();

        ProcessPing::dispatchSync($payload);

        $event = ErrorEvent::findOrFail($payload['id']);
        $issue = $event->issue;

        $this->assertSame('TypeError', $event->exception_type);
        $this->assertSame('/assets/index-3fa9c1d2.js', $event->file);
        $this->assertSame(42, $event->line);
        $this->assertSame('bugwatcher-js/0.1.0', $event->sdk);
        $this->assertSame(['id' => '42'], $event->user_context);
        $this->assertCount(2, $event->stacktrace);
        $this->assertSame('2025-10-09 08:53:20.500', $event->occurred_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2025-10-09 08:53:21.000', $event->received_at->format('Y-m-d H:i:s.v'));
        $this->assertNotNull($event->processed_at);
        $this->assertFalse($event->is_regression);

        $this->assertSame("TypeError: Cannot read properties of undefined (reading 'total')", $issue->title);
        $this->assertSame('/assets/index-3fa9c1d2.js:42', $issue->culprit);
        $this->assertSame('open', $issue->status);
        $this->assertSame(1, $issue->event_count);
        $this->assertSame(64, strlen($issue->fingerprint));

        Event::assertDispatched(PingProcessed::class, fn (PingProcessed $e) => $e->isNewIssue
            && ! $e->isRegression
            && $e->event->id === $payload['id']
            && $e->issue->id === $issue->id);
    }

    public function test_the_same_error_increments_one_issue(): void
    {
        Event::fake([PingProcessed::class]);

        ProcessPing::dispatchSync($this->payload());
        ProcessPing::dispatchSync($this->payload(['received_at' => 1760000009000]));

        $issue = Issue::sole();
        $this->assertSame(2, $issue->event_count);
        $this->assertSame('2025-10-09 08:53:29.000', $issue->last_seen_at->format('Y-m-d H:i:s.v'));
        $this->assertSame(2, ErrorEvent::count());
        Event::assertDispatchedTimes(PingProcessed::class, 2);
        Event::assertDispatched(PingProcessed::class, fn (PingProcessed $e) => ! $e->isNewIssue && $e->issue->event_count === 2);
    }

    public function test_a_different_line_or_build_hash_in_the_same_function_groups_together(): void
    {
        ProcessPing::dispatchSync($this->payload());
        ProcessPing::dispatchSync($this->payload(frames: [
            ['file' => 'https://cdn.shop.test/assets/index-9b8e7f6a.js', 'line' => 57, 'function' => 'Cart.total'],
        ]));

        $this->assertSame(1, Issue::count());
        $this->assertSame(2, Issue::sole()->event_count);
    }

    public function test_a_different_function_or_type_is_a_different_issue(): void
    {
        ProcessPing::dispatchSync($this->payload());
        ProcessPing::dispatchSync($this->payload(frames: [['file' => '/assets/index.js', 'line' => 42, 'function' => 'Cart.checkout']]));
        ProcessPing::dispatchSync($this->payload(['exception' => ['type' => 'RangeError', 'frames' => $this->payload()['exception']['frames']]]));

        $this->assertSame(3, Issue::count());
    }

    public function test_without_frames_the_normalized_message_is_the_fingerprint(): void
    {
        ProcessPing::dispatchSync($this->payload(['exception' => null, 'message' => 'Order 1234 failed for 550e8400-e29b-41d4-a716-446655440000']));
        ProcessPing::dispatchSync($this->payload(['exception' => null, 'message' => 'Order 98 failed for 6ba7b810-9dad-11d1-80b4-00c04fd430c8']));
        ProcessPing::dispatchSync($this->payload(['exception' => null, 'message' => 'Payment declined']));

        $this->assertEqualsCanonicalizing([1, 2], Issue::pluck('event_count')->all());
    }

    public function test_a_resolved_issue_that_recurs_is_reopened_as_a_regression(): void
    {
        Event::fake([PingProcessed::class]);
        ProcessPing::dispatchSync($this->payload());
        Issue::query()->update(['status' => 'resolved']);

        $payload = $this->payload();
        ProcessPing::dispatchSync($payload);

        $this->assertSame('open', Issue::sole()->status);
        $this->assertTrue(ErrorEvent::findOrFail($payload['id'])->is_regression);
        Event::assertDispatched(PingProcessed::class, fn (PingProcessed $e) => $e->isRegression && ! $e->isNewIssue);
    }

    public function test_a_payload_delivered_twice_is_stored_once(): void
    {
        Event::fake([PingProcessed::class]);
        $payload = $this->payload();

        ProcessPing::dispatchSync($payload);
        ProcessPing::dispatchSync($payload);

        $this->assertSame(1, ErrorEvent::count());
        $this->assertSame(1, Issue::sole()->event_count);
        Event::assertDispatchedTimes(PingProcessed::class, 1);
    }

    public function test_only_the_first_event_after_a_resolve_is_a_regression(): void
    {
        ProcessPing::dispatchSync($this->payload());
        Issue::query()->update(['status' => 'resolved']);

        $regression = $this->payload();
        $next = $this->payload();
        ProcessPing::dispatchSync($regression);
        ProcessPing::dispatchSync($next);

        $this->assertTrue(ErrorEvent::findOrFail($regression['id'])->is_regression);
        $this->assertFalse(ErrorEvent::findOrFail($next['id'])->is_regression);
        $this->assertSame('open', Issue::sole()->status);
        $this->assertSame(3, Issue::sole()->event_count);
    }

    public function test_a_late_ping_is_counted_without_moving_last_seen_back(): void
    {
        ProcessPing::dispatchSync($this->payload(['received_at' => 1760000009000]));
        ProcessPing::dispatchSync($this->payload(['received_at' => 1760000001000, 'level' => 'warning', 'environment' => 'staging']));

        $issue = Issue::sole();
        $this->assertSame(2, $issue->event_count);
        $this->assertSame('2025-10-09 08:53:29.000', $issue->first_seen_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2025-10-09 08:53:29.000', $issue->last_seen_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('warning', $issue->level);
        $this->assertSame('staging', $issue->environment);
    }

    public function test_on_postgres_a_processed_ping_costs_one_database_statement(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The single-statement path is PostgreSQL only.');
        }
        // Warms the cached project slug the broadcast uses.
        ProcessPing::dispatchSync($this->payload());

        $statements = 0;
        DB::listen(function () use (&$statements) {
            $statements++;
        });
        ProcessPing::dispatchSync($this->payload());
        $this->assertSame(1, $statements, 'A ping for an existing issue');

        ProcessPing::dispatchSync($this->payload(['exception' => ['type' => 'RangeError', 'frames' => $this->payload()['exception']['frames']]]));
        $this->assertSame(2, $statements, 'A ping for a new issue');

        $this->assertSame([2, 1], Issue::orderBy('id')->pluck('event_count')->all());
    }

    public function test_when_the_database_is_down_the_payload_goes_to_the_spool(): void
    {
        $spool = $this->spool();
        $down = new QueryException('pgsql', 'insert into "issues"', [], new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused'));
        $processor = Mockery::mock(PingProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow($down);
        $payload = $this->payload();

        (new ProcessPing($payload))->handle($processor, $spool);

        $this->assertSame(1, $spool->size());
        $this->assertSame($payload, json_decode($spool->pop(), true));
    }

    public function test_other_errors_are_retried_and_a_job_that_fails_for_good_is_spooled(): void
    {
        $spool = $this->spool();
        $processor = Mockery::mock(PingProcessor::class);
        $processor->shouldReceive('process')->andThrow(new RuntimeException('bug'));
        $job = new ProcessPing($this->payload());

        try {
            $job->handle($processor, $spool);
            $this->fail('The exception should be rethrown so the queue retries the job.');
        } catch (RuntimeException) {
            $this->assertSame(0, $spool->size());
        }

        $job->failed(new RuntimeException('bug'));

        $spooled = json_decode($spool->pop(), true);
        $this->assertSame(1, $spooled['spool_cycles']);
        $this->assertSame($job->payload['id'], $spooled['id']);
    }

    public function test_drain_spool_requeues_payloads_and_sets_aside_ones_that_keep_failing(): void
    {
        $spool = $this->spool();
        Queue::fake();
        $spool->push($this->payload());
        $spool->push($this->payload());
        $spool->push($this->payload(['spool_cycles' => 10]));

        $this->artisan('pings:drain-spool')
            ->expectsOutput('Requeued 2 spooled ping(s); 1 set aside; 0 left.')
            ->assertSuccessful();

        Queue::assertPushed(ProcessPing::class, 2);
        Queue::assertPushedOn('ingest', ProcessPing::class);
        $this->assertSame(0, $spool->size());
        $this->assertSame(1, $spool->deadLetterSize());
    }
}

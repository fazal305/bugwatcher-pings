<?php

namespace App\Pings;

use App\Jobs\ProcessPing;
use Illuminate\Support\Str;

/**
 * The last step of ingestion, shared by the public ping route and the dashboard's test error: mask, then queue.
 */
class PingQueue
{
    public function __construct(private Masker $masker) {}

    /** Takes a payload that passed PingValidator and returns the id the stored event will have. */
    public function push(array $ping, int $projectId): string
    {
        $ping = $this->masker->mask($ping);
        if (isset($ping['request']['url'])) {
            $ping['request']['url'] = $this->masker->maskUrl($ping['request']['url']);
        }
        if (isset($ping['request']['query']) && is_string($ping['request']['query'])) {
            $ping['request']['query'] = $this->masker->maskQueryString(ltrim($ping['request']['query'], '?'));
        }

        $id = (string) Str::ulid();
        ProcessPing::dispatch($ping + [
            'id' => $id,
            'project_id' => $projectId,
            'received_at' => (int) floor(microtime(true) * 1000),
        ])->onQueue(config('pings.queue'));

        return $id;
    }
}

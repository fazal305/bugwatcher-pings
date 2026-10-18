<?php

namespace App\Events;

use App\Models\ErrorEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Realtime\FeedItem;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast straight from the queue worker that stored the ping: the job is already off the request path, so queueing
 * the broadcast as well would only add latency.
 */
class PingProcessed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public ErrorEvent $event,
        public Issue $issue,
        public bool $isNewIssue,
        public bool $isRegression,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('project.'.Project::whereKey($this->event->project_id)->value('slug'));
    }

    public function broadcastAs(): string
    {
        return 'ping.processed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return FeedItem::make($this->event, $this->issue, $this->isNewIssue);
    }
}

<?php

namespace App\Listeners;

use App\Events\PingProcessed;
use App\Jobs\SendWebhook;
use App\Models\WebhookEndpoint;
use App\Webhooks\Alert;
use App\Webhooks\Cooldown;
use App\Webhooks\EndpointCache;
use App\Webhooks\WebhookSender;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueWebhookAlerts
{
    public function __construct(private EndpointCache $endpoints, private Cooldown $cooldown, private WebhookSender $sender) {}

    public function handle(PingProcessed $ping): void
    {
        // The ping is already stored; nothing here may fail the job that stored it, or a retry would find it
        // processed and the alert would be lost for good.
        try {
            $this->queue($ping);
        } catch (Throwable $e) {
            Log::error('Queueing webhook alerts failed.', ['event' => $ping->event->id, 'error' => $e->getMessage()]);
        }
    }

    private function queue(PingProcessed $ping): void
    {
        $project = $this->endpoints->forProject((int) $ping->event->project_id);
        if ($project['endpoints'] === [] || $project['slug'] === null) {
            return;
        }

        $level = WebhookEndpoint::levelRank((string) $ping->event->level);
        $environment = (string) $ping->event->environment;
        $issueId = (int) $ping->issue->id;
        $bypass = $ping->isNewIssue || $ping->isRegression;
        $alert = null;
        $throttled = [];

        foreach ($project['endpoints'] as $endpoint) {
            if ($level < WebhookEndpoint::levelRank($endpoint['min_level']) || ! in_array($environment, $endpoint['environments'], true)) {
                continue;
            }

            $alert ??= Alert::fromPing($ping, $project['slug'], (string) $project['name']);
            if (! $this->cooldown->acquire($endpoint['id'], $issueId, $endpoint['cooldown_seconds'], $bypass)) {
                $throttled[] = $endpoint['id'];

                continue;
            }

            SendWebhook::dispatch($endpoint['id'], $alert, $ping->firedAt)->onQueue(config('webhooks.queue'));
        }

        // Recorded after every send is queued, so these inserts don't delay the alerts.
        foreach ($throttled as $endpointId) {
            $this->sender->recordThrottled($endpointId, $alert);
        }
    }
}

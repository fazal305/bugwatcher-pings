<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retries happen inside the one attempt (see WebhookSender), so a failed job is never run again and an endpoint
 * can't receive the same alert twice.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $endpointId, public array $alert, public float $firedAt) {}

    public function handle(WebhookSender $sender): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);
        if ($endpoint === null || ! $endpoint->enabled) {
            return;
        }

        $sender->deliver($endpoint, $this->alert, $this->firedAt, (int) config('webhooks.tries'));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookRequest;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Realtime\FeedItem;
use App\Webhooks\Alert;
use App\Webhooks\WebhookSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The URL of a Slack or Discord webhook is itself the credential, so neither it nor a signing secret is ever sent
 * back after creation (the secret is shown once, in the create reply).
 */
class WebhookController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->webhooks()->orderBy('id')->get()->map(self::present(...))->values()]);
    }

    public function store(WebhookRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $secret = $data['kind'] === 'generic' ? 'whsec_'.Str::random(40) : null;
        $endpoint = $project->webhooks()->create([...$data, 'signing_secret' => $secret]);

        $body = self::present($endpoint->fresh());
        if ($secret !== null) {
            $body['signing_secret'] = $secret;
        }

        return response()->json(['data' => $body], 201);
    }

    public function update(WebhookRequest $request, Project $project, WebhookEndpoint $webhook): JsonResponse
    {
        $webhook->update($request->validated());

        return response()->json(['data' => self::present($webhook->fresh())]);
    }

    public function destroy(Project $project, WebhookEndpoint $webhook): Response
    {
        $webhook->delete();

        return response()->noContent();
    }

    // Sent while the admin waits, with a single try, so the reply says straight away whether it worked. A failed send
    // is still a 200: the request itself succeeded, and the delivery in the reply carries the outcome.
    public function test(Project $project, WebhookEndpoint $webhook, WebhookSender $sender): JsonResponse
    {
        $delivery = $sender->deliver($webhook, Alert::test($project), null, 1);
        $webhook->refresh();

        return response()->json([
            'data' => [
                'delivery' => self::presentDelivery($delivery),
                'endpoint' => self::present($webhook),
                'message' => $delivery->status === 'success' ? 'Test message sent.' : ($webhook->last_error ?? 'The test message could not be sent.'),
            ],
        ]);
    }

    public function deliveries(Project $project, WebhookEndpoint $webhook): JsonResponse
    {
        $deliveries = $webhook->deliveries()->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $deliveries->map(self::presentDelivery(...))->values()]);
    }

    /** @return array<string, mixed> */
    private static function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'kind' => $endpoint->kind,
            'name' => $endpoint->name,
            'url_masked' => $endpoint->maskedUrl(),
            'signed' => $endpoint->kind === 'generic',
            'min_level' => $endpoint->min_level,
            'environments' => array_values((array) $endpoint->environments),
            'cooldown_seconds' => $endpoint->cooldown_seconds,
            'enabled' => $endpoint->enabled,
            'last_status' => $endpoint->last_status,
            'last_error' => $endpoint->last_error,
            'last_delivered_at' => FeedItem::time($endpoint->last_delivered_at),
            'created_at' => FeedItem::time($endpoint->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function presentDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'issue_id' => $delivery->issue_id,
            'test' => $delivery->event_id === null,
            'status' => $delivery->status,
            'http_status' => $delivery->http_status,
            'dispatch_delay_ms' => $delivery->dispatch_delay_ms,
            'duration_ms' => $delivery->duration_ms,
            'created_at' => FeedItem::time($delivery->created_at),
        ];
    }
}

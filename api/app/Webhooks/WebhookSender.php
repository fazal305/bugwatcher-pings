<?php

namespace App\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookSender
{
    public function __construct(private UrlGuard $guard, private Payloads $payloads) {}

    /**
     * Sends one alert and records the outcome. $firedAt is when PingProcessed was fired (microtime), so the recorded
     * dispatch delay covers the broadcast, the queue hop and the URL check, up to the moment the request starts.
     */
    public function deliver(WebhookEndpoint $endpoint, array $alert, ?float $firedAt, int $tries): WebhookDelivery
    {
        try {
            $target = $this->guard->check($endpoint->kind, (string) $endpoint->url);
        } catch (UnsafeUrl) {
            return $this->record($endpoint, $alert, 'failed', null, $this->since($firedAt), null, 'Blocked: this URL is no longer allowed.');
        }

        $payload = $this->payloads->build($endpoint->kind, $alert, $endpoint->kind === 'generic' ? $endpoint->signing_secret : null);
        $delays = config('webhooks.retry_delays');

        $started = microtime(true);
        $delay = $this->since($firedAt, $started);
        $status = null;
        $error = null;
        try {
            $response = Http::connectTimeout(config('webhooks.connect_timeout'))
                ->timeout(config('webhooks.timeout'))
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$target->curlResolveEntry()]]])
                ->withUserAgent('BugWatcher-Pings-Webhooks/1.0')
                ->withHeaders($payload['headers'])
                ->retry(
                    max(1, $tries),
                    fn (int $attempt) => $delays[$attempt - 1] ?? end($delays),
                    fn (?Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                    throw: false,
                )
                ->withBody($payload['body'], 'application/json')
                ->post($target->url);

            $status = $response->status();
            if (! $response->successful()) {
                // Only the status code is kept: a response body could echo the URL or anything else back.
                $error = "The endpoint answered HTTP {$status}.";
            }
        } catch (ConnectionException) {
            $error = 'Could not connect, or the endpoint did not answer in time.';
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        return $this->record($endpoint, $alert, $error === null ? 'success' : 'failed', $status, $delay, $duration, $error);
    }

    public function recordThrottled(int $endpointId, array $alert): void
    {
        WebhookDelivery::query()->insert([
            'endpoint_id' => $endpointId,
            'event_id' => $alert['event_id'],
            'issue_id' => $alert['issue_id'],
            'status' => 'throttled',
            'created_at' => Date::now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function record(WebhookEndpoint $endpoint, array $alert, string $status, ?int $httpStatus, ?int $delay, ?int $duration, ?string $error): WebhookDelivery
    {
        $now = Date::now()->format('Y-m-d H:i:s.v');
        $delivery = WebhookDelivery::create([
            'endpoint_id' => $endpoint->id,
            'event_id' => $alert['event_id'],
            'issue_id' => $alert['issue_id'],
            'status' => $status,
            'http_status' => $httpStatus,
            'dispatch_delay_ms' => $delay,
            'duration_ms' => $duration,
            'created_at' => $now,
        ]);

        $update = ['last_status' => $status, 'last_error' => $error];
        if ($status === 'success') {
            $update['last_delivered_at'] = $now;
        }
        // A query update, so the endpoint cache isn't cleared by every delivery.
        WebhookEndpoint::query()->whereKey($endpoint->id)->update($update);

        return $delivery;
    }

    private function since(?float $firedAt, ?float $now = null): ?int
    {
        return $firedAt === null ? null : max(0, (int) round((($now ?? microtime(true)) - $firedAt) * 1000));
    }
}

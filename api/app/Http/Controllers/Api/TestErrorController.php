<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Pings\PingQueue;
use App\Pings\PingValidator;
use Illuminate\Http\JsonResponse;

/**
 * Sends a labelled test error through the same validation, masking, queue and worker as a real ping, so an admin (or a
 * demo visitor) can watch a card arrive in the live feed without setting up an SDK.
 */
class TestErrorController extends Controller
{
    public const PREFIX = '[Test] ';

    public function __invoke(Project $project, PingValidator $validator, PingQueue $queue): JsonResponse
    {
        [$ping] = $validator->validate([
            'message' => self::PREFIX."Cannot read properties of undefined (reading 'after')",
            'level' => 'error',
            'environment' => 'production',
            'timestamp' => (int) floor(microtime(true) * 1000),
            'release' => 'test',
            'exception' => [
                'type' => 'TypeError',
                'frames' => [
                    [
                        'file' => 'https://storefront.example/assets/coupons-7c1e9a4b.js',
                        'line' => 27,
                        'function' => 'applyCoupon',
                        'in_app' => true,
                        'pre_context' => ['export function applyCoupon(cart, coupon) {', '  // Sent by the dashboard\'s "Fire a test error" button'],
                        'context_line' => '  return { ...cart, total: cart.totals.after.toFixed(2), coupon: coupon.code }',
                        'post_context' => ['}'],
                    ],
                ],
            ],
            'request' => [
                'method' => 'POST',
                'url' => 'https://storefront.example/checkout?coupon=AUTUMN&token=test-token-value',
                'headers' => ['Authorization' => 'Bearer test-token-value', 'Content-Type' => 'application/json'],
                'body' => ['email' => 'test.customer@example.com', 'password' => 'not-a-real-password'],
            ],
            'user' => ['id' => 'test-user'],
            'sdk' => ['name' => 'bugwatcher-dashboard', 'version' => 'test'],
        ]);

        $id = $queue->push($ping, $project->id);

        return response()->json(['id' => $id, 'message' => 'Test error sent. It will appear in the live feed in a moment.'], 202);
    }
}

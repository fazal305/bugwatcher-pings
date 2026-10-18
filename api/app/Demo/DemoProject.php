<?php

namespace App\Demo;

use App\Enums\ProjectRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared demo account's project and its labelled sample data. Sample rows carry is_sample and are only ever
 * created here, in this one project, never in anyone else's.
 */
class DemoProject
{
    public const NAME = 'Demo Storefront (sample data)';

    // Found by membership rather than by name, since visitors can rename it.
    public static function find(): ?Project
    {
        $user = User::where('email', User::DEMO_EMAIL)->first();

        return $user ? Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))->orderBy('id')->first() : null;
    }

    // The password is random and never shown: the demo is only reachable through POST /api/auth/demo.
    public static function ensure(): Project
    {
        return DB::transaction(function () {
            $user = User::firstOrCreate(['email' => User::DEMO_EMAIL], ['name' => 'Demo', 'password' => Str::random(64)]);
            $project = self::find();
            if ($project === null) {
                $project = Project::create(['name' => self::NAME]);
                $project->members()->create(['user_id' => $user->id, 'role' => ProjectRole::Admin]);
            }
            if (! $project->issues()->where('is_sample', true)->exists()) {
                self::seedSamples($project);
            }

            return $project;
        });
    }

    /**
     * Puts the demo back to its seeded state: fresh sample data, the original name, no extra projects created from the
     * demo account, and only the visitors' own events from the last $keepHours hours.
     *
     * @return array{events: int, projects: int}
     */
    public static function reset(int $keepHours): array
    {
        $project = self::ensure();
        $user = User::where('email', User::DEMO_EMAIL)->sole();

        return DB::transaction(function () use ($project, $user, $keepHours) {
            // The demo account can't add members, so a project it created has nobody else in it.
            $extra = Project::whereKeyNot($project->id)->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->get();
            $extra->each->delete();

            $cutoff = now()->subHours($keepHours)->utc()->format('Y-m-d H:i:s.v');
            $events = $project->events()->where(fn ($q) => $q->where('is_sample', true)->orWhere('received_at', '<', $cutoff))->delete();
            $project->issues()->where(fn ($q) => $q->where('is_sample', true)->orWhereDoesntHave('events'))->delete();
            foreach ($project->issues()->withCount('events')->get() as $issue) {
                $issue->update(['event_count' => $issue->events_count]);
            }

            if ($project->name !== self::NAME) {
                $project->update(['name' => self::NAME]);
            }
            self::seedSamples($project);

            return ['events' => $events, 'projects' => $extra->count()];
        });
    }

    private static function seedSamples(Project $project): void
    {
        $now = now();
        foreach (self::samples() as $key => $sample) {
            $offsets = $sample['minutes_ago'];
            $top = $sample['frames'][0];
            $issue = Issue::create([
                'project_id' => $project->id,
                'fingerprint' => hash('sha256', 'sample:'.$key),
                'title' => $sample['type'].': '.$sample['message'],
                'culprit' => $top['file'].':'.$top['line'],
                'level' => $sample['level'],
                'environment' => $sample['environment'],
                'status' => $sample['status'] ?? 'open',
                'first_seen_at' => self::time($now->copy()->subMinutes(max($offsets))),
                'last_seen_at' => self::time($now->copy()->subMinutes(min($offsets))),
                'event_count' => count($offsets),
                'is_sample' => true,
            ]);

            foreach ($offsets as $minutes) {
                $received = $now->copy()->subMinutes($minutes);
                DB::table('events')->insert([
                    'id' => (string) Str::ulid($received),
                    'project_id' => $project->id,
                    'issue_id' => $issue->id,
                    'level' => $sample['level'],
                    'environment' => $sample['environment'],
                    'message' => $sample['message'],
                    'exception_type' => $sample['type'],
                    'file' => $top['file'],
                    'line' => $top['line'],
                    'stacktrace' => json_encode($sample['frames'], JSON_UNESCAPED_SLASHES),
                    'request' => isset($sample['request']) ? json_encode($sample['request'], JSON_UNESCAPED_SLASHES) : null,
                    'user_context' => json_encode(['id' => 'sample-customer-'.(100 + $minutes)]),
                    'release' => $sample['release'],
                    'sdk' => $sample['sdk'],
                    'is_regression' => false,
                    'is_sample' => true,
                    'occurred_at' => self::time($received->copy()->subMilliseconds(180)),
                    'received_at' => self::time($received),
                    'processed_at' => self::time($received->copy()->addMilliseconds(24)),
                ]);
            }
        }
    }

    private static function time(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i:s.v');
    }

    /** @return array<string, array<string, mixed>> */
    private static function samples(): array
    {
        $filtered = '[Filtered]';

        return [
            'cart-total' => [
                'type' => 'TypeError',
                'message' => "Cannot read properties of undefined (reading 'toFixed')",
                'level' => 'error',
                'environment' => 'production',
                'release' => '2.8.1',
                'sdk' => 'bugwatcher-js/1.0.0',
                'minutes_ago' => [4, 19, 47, 96],
                'request' => [
                    'method' => 'GET',
                    'url' => "https://storefront.example/cart?coupon=AUTUMN&token={$filtered}",
                    'headers' => ['Authorization' => $filtered, 'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'],
                ],
                'frames' => [
                    [
                        'file' => '/assets/cart.js', 'line' => 42, 'function' => 'Cart.total', 'in_app' => true,
                        'pre_context' => ['export function total(cart) {', '  const items = cart.items.filter((item) => item.qty > 0)', '  // Discounts are applied before tax'],
                        'context_line' => '  return cart.summary.total.toFixed(2)',
                        'post_context' => ['}', '', 'export function count(cart) {'],
                    ],
                    ['file' => '/assets/vendor-react.js', 'line' => 1180, 'function' => 'renderWithHooks', 'in_app' => false],
                ],
            ],
            'order-deadlock' => [
                'type' => 'PDOException',
                'message' => 'SQLSTATE[40P01]: Deadlock detected while updating stock levels',
                'level' => 'fatal',
                'environment' => 'production',
                'release' => '2.8.1',
                'sdk' => 'bugwatcher-php/1.0.0',
                'minutes_ago' => [11, 132],
                'request' => [
                    'method' => 'POST',
                    'url' => 'https://storefront.example/checkout',
                    'headers' => ['Cookie' => $filtered, 'Content-Type' => 'application/json'],
                    'body' => ['email' => 'sample.customer@example.com', 'card_number' => $filtered, 'cvv' => $filtered],
                ],
                'frames' => [
                    [
                        'file' => '/var/www/app/Http/Controllers/OrderController.php', 'line' => 88, 'function' => 'OrderController->store', 'in_app' => true,
                        'pre_context' => ['    public function store(OrderRequest $request): JsonResponse', '    {', '        $order = DB::transaction(function () use ($request) {'],
                        'context_line' => "            Stock::whereIn('sku', \$request->skus())->lockForUpdate()->decrement('quantity');",
                        'post_context' => ['', '            return Order::create($request->validated());', '        });'],
                    ],
                    ['file' => '/var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 825, 'function' => 'Connection->runQueryCallback', 'in_app' => false],
                ],
            ],
            'slow-payment' => [
                'type' => 'GatewayTimeout',
                'message' => 'Payment provider took 2.4 s to answer',
                'level' => 'warning',
                'environment' => 'production',
                'release' => '2.8.1',
                'sdk' => 'bugwatcher-php/1.0.0',
                'minutes_ago' => [26, 58, 171],
                'frames' => [
                    [
                        'file' => '/var/www/app/Services/PaymentGateway.php', 'line' => 57, 'function' => 'PaymentGateway->charge', 'in_app' => true,
                        'pre_context' => ['        $started = microtime(true);', "        \$response = \$this->client->post('/charges', \$payload);", '        $seconds = microtime(true) - $started;'],
                        'context_line' => "        if (\$seconds > 2.0) report(new GatewayTimeout(sprintf('Payment provider took %.1f s to answer', \$seconds)));",
                        'post_context' => ['', '        return $response->json();', '    }'],
                    ],
                ],
            ],
            'search-fetch' => [
                'type' => 'TypeError',
                'message' => 'Failed to fetch search suggestions',
                'level' => 'error',
                'environment' => 'staging',
                'release' => '2.9.0-rc.1',
                'sdk' => 'bugwatcher-js/1.0.0',
                'minutes_ago' => [35, 64],
                'frames' => [
                    [
                        'file' => '/src/search/suggest.ts', 'line' => 18, 'function' => 'fetchSuggestions', 'in_app' => true,
                        'pre_context' => ['export async function fetchSuggestions(query: string): Promise<string[]> {', '  const url = `/api/suggest?q=${encodeURIComponent(query)}`'],
                        'context_line' => '  const response = await fetch(url, { signal: AbortSignal.timeout(3000) })',
                        'post_context' => ['  return (await response.json()) as string[]', '}'],
                    ],
                ],
            ],
            'expired-coupon' => [
                'type' => 'CouponExpired',
                'message' => 'Coupon AUTUMN expired before checkout',
                'level' => 'info',
                'environment' => 'development',
                'release' => 'dev',
                'sdk' => 'bugwatcher-php/1.0.0',
                'minutes_ago' => [73],
                'frames' => [
                    [
                        'file' => '/var/www/app/Pricing/Coupons.php', 'line' => 31, 'function' => 'Coupons->apply', 'in_app' => true,
                        'pre_context' => ['    public function apply(Cart $cart, Coupon $coupon): Cart', '    {'],
                        'context_line' => '        if ($coupon->expires_at->isPast()) throw new CouponExpired("Coupon {$coupon->code} expired before checkout");',
                        'post_context' => ['', '        return $cart->withDiscount($coupon->percent);'],
                    ],
                ],
            ],
            'missing-sku' => [
                'type' => 'ErrorException',
                'message' => 'Undefined array key "sku" in product import',
                'level' => 'error',
                'environment' => 'production',
                'release' => '2.8.0',
                'sdk' => 'bugwatcher-php/1.0.0',
                'status' => 'resolved',
                'minutes_ago' => [214],
                'frames' => [
                    [
                        'file' => '/var/www/app/Imports/ProductImport.php', 'line' => 23, 'function' => 'ProductImport->row', 'in_app' => true,
                        'pre_context' => ['    public function row(array $row): Product', '    {'],
                        'context_line' => "        return Product::updateOrCreate(['sku' => \$row['sku']], ['name' => \$row['name'], 'price' => \$row['price']]);",
                        'post_context' => ['    }'],
                    ],
                ],
            ],
        ];
    }
}

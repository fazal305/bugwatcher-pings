<?php

namespace App\Models;

use App\Webhooks\EndpointCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    public const KINDS = ['slack', 'discord', 'generic'];

    public const LEVELS = ['info', 'warning', 'error', 'fatal'];

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

    protected $fillable = ['kind', 'name', 'url', 'signing_secret', 'min_level', 'environments', 'cooldown_seconds', 'enabled'];

    protected $hidden = ['url', 'signing_secret'];

    protected $attributes = [
        'min_level' => 'error',
        'environments' => '["production"]',
        'cooldown_seconds' => 300,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'signing_secret' => 'encrypted',
            'environments' => 'array',
            'cooldown_seconds' => 'integer',
            'enabled' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn (WebhookEndpoint $endpoint) => app(EndpointCache::class)->forget($endpoint->project_id);
        static::saved($forget);
        static::deleted($forget);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public static function levelRank(string $level): int
    {
        return (int) array_search($level, self::LEVELS, true);
    }

    // Enough to tell endpoints apart without letting the URL, which is itself the credential, be read back.
    public function maskedUrl(): string
    {
        $url = (string) $this->url;
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host.'/…'.substr($url, -4);
    }
}

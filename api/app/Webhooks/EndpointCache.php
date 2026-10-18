<?php

namespace App\Webhooks;

use App\Models\Project;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Cache\Repository;

/**
 * The enabled endpoints of a project, as the alert listener needs them for every processed ping. URLs and secrets
 * are not cached; the job that sends a webhook reads its endpoint from the database.
 */
class EndpointCache
{
    public function __construct(private Repository $cache, private int $ttl) {}

    /** @return array{slug: ?string, name: ?string, endpoints: list<array{id: int, min_level: string, environments: list<string>, cooldown_seconds: int}>} */
    public function forProject(int $projectId): array
    {
        return $this->cache->remember($this->key($projectId), $this->ttl, function () use ($projectId) {
            $project = Project::query()->select(['id', 'slug', 'name'])->find($projectId);

            return [
                'slug' => $project?->slug,
                'name' => $project?->name,
                'endpoints' => WebhookEndpoint::query()
                    ->where('project_id', $projectId)
                    ->where('enabled', true)
                    ->orderBy('id')
                    ->get(['id', 'min_level', 'environments', 'cooldown_seconds'])
                    ->map(fn (WebhookEndpoint $e) => [
                        'id' => $e->id,
                        'min_level' => $e->min_level,
                        'environments' => array_values((array) $e->environments),
                        'cooldown_seconds' => $e->cooldown_seconds,
                    ])
                    ->all(),
            ];
        });
    }

    public function forget(int $projectId): void
    {
        $this->cache->forget($this->key($projectId));
    }

    private function key(int $projectId): string
    {
        return "webhooks:project:{$projectId}";
    }
}

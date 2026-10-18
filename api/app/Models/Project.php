<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Webhooks\EndpointCache;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->slug ??= static::uniqueSlug($project->name);
            $project->public_key ??= static::newPublicKey();
        });
        // Alerts carry the project's name, which the webhook endpoint cache holds.
        static::updated(fn (Project $project) => app(EndpointCache::class)->forget($project->id));
    }

    public static function newPublicKey(): string
    {
        return 'bwp_pk_'.Str::random(40);
    }

    // The random suffix keeps slugs unique and stops them being guessed from a project's name.
    private static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'project';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<ErrorEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ErrorEvent::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasMany<WebhookEndpoint, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function membershipFor(User $user): ?ProjectMember
    {
        return $this->members->firstWhere('user_id', $user->id);
    }

    public function roleOf(User $user): ?ProjectRole
    {
        return $this->membershipFor($user)?->role;
    }

    public function adminCount(): int
    {
        return $this->members()->where('role', ProjectRole::Admin)->count();
    }
}

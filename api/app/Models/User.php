<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const DEMO_EMAIL = 'demo@bugwatcher-pings.invalid';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<ProjectMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function isDemo(): bool
    {
        return $this->email === self::DEMO_EMAIL;
    }

    public function issueToken(): string
    {
        return $this->createToken('spa', ['*'], now()->addMinutes((int) config('sanctum.expiration')))->plainTextToken;
    }

    /** @return array<string, mixed> */
    public function toProfile(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'is_demo' => $this->isDemo(),
        ];
    }
}

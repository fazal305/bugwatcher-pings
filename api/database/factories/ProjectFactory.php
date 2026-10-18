<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company()];
    }

    public function withMember(User $user, ProjectRole $role = ProjectRole::Admin): static
    {
        return $this->afterCreating(fn (Project $project) => $project->members()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]));
    }
}

<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    // Non-members get the same 404 as a project that doesn't exist, so slugs can't be probed.
    public function view(User $user, Project $project): Response
    {
        return $project->roleOf($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function manage(User $user, Project $project): Response
    {
        return match ($project->roleOf($user)) {
            ProjectRole::Admin => Response::allow(),
            ProjectRole::Developer => Response::deny('Only project admins can do that.'),
            null => Response::denyAsNotFound(),
        };
    }
}

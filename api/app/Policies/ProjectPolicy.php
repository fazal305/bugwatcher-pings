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

    // The demo account is shared by every visitor, so it must not be able to let anyone else in or lock others out.
    public function manageAccess(User $user, Project $project): Response
    {
        $response = $this->manage($user, $project);
        if ($response->allowed() && $user->isDemo()) {
            return Response::deny("The shared demo account can't change members or rotate keys. Create your own account to try this.");
        }

        return $response;
    }

    // Alerts send requests to any URL an admin enters, so the shared demo account must not be usable as a relay.
    public function manageWebhooks(User $user, Project $project): Response
    {
        $response = $this->manage($user, $project);
        if ($response->allowed() && $user->isDemo()) {
            return Response::deny("The shared demo account can't add, change or test alerts. Create your own account to try this.");
        }

        return $response;
    }
}

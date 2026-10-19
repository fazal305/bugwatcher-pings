<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $members = $project->members()->with('user:id,name,email')->get()
            ->each(fn (ProjectMember $member) => $member->setRelation('project', $project))
            ->sortBy([
                fn ($a, $b) => ($a->role === ProjectRole::Admin ? 0 : 1) <=> ($b->role === ProjectRole::Admin ? 0 : 1),
                fn ($a, $b) => strcasecmp($a->user->name, $b->user->name),
            ])
            ->values();

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, Project $project, ProjectMember $member): MemberResource
    {
        $role = ProjectRole::from($request->validated('role'));

        DB::transaction(function () use ($project, $member, $role) {
            $this->lockMembers($project);
            if ($role !== ProjectRole::Admin) {
                $this->ensureAnotherAdmin($project, $member, 'role');
            }
            $member->update(['role' => $role]);
        });

        $project->unsetRelation('members');

        return new MemberResource($member->setRelation('project', $project)->load('user:id,name,email'));
    }

    public function destroy(Project $project, ProjectMember $member): JsonResponse
    {
        DB::transaction(function () use ($project, $member) {
            $this->lockMembers($project);
            $this->ensureAnotherAdmin($project, $member, 'member');
            $member->delete();
        });

        return response()->json(null, 204);
    }

    // Serialises admin changes per project, so two admins demoting each other can't leave it with none.
    private function lockMembers(Project $project): void
    {
        Project::whereKey($project->id)->lockForUpdate()->first();
    }

    private function ensureAnotherAdmin(Project $project, ProjectMember $member, string $field): void
    {
        if ($member->fresh()->role === ProjectRole::Admin && $project->adminCount() <= 1) {
            throw ValidationException::withMessages([$field => 'A project needs at least one admin. Make someone else an admin first.']);
        }
    }
}

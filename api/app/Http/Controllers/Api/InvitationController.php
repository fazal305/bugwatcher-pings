<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteMemberRequest;
use App\Models\Project;
use App\Models\ProjectInvitation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    private const INVALID = 'This invitation link is invalid or has expired.';

    private const WRONG_EMAIL = 'This invitation was sent to a different email address. Sign in with that address to accept it.';

    private const DEMO = "The shared demo account can't accept invitations. Create your own account to accept this one.";

    public function index(Project $project): JsonResponse
    {
        $invitations = $project->invitations()->pending()->with('inviter:id,name')->orderBy('created_at')->get();

        return response()->json(['data' => $invitations->map(fn (ProjectInvitation $invitation) => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'invited_by' => $invitation->inviter?->name,
        ])]);
    }

    /**
     * Never looks up accounts by email, and answers the same way for an unknown address, an existing account and a
     * current member, so this can't be used to find out which emails are registered. The link is the only way in.
     */
    public function store(InviteMemberRequest $request, Project $project): JsonResponse
    {
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($request, $project, $token) {
            // Serialises invites per project, so the same email can't end up with two pending invitations.
            Project::whereKey($project->id)->lockForUpdate()->first();
            $project->invitations()->where('email', $request->validated('email'))->whereNull('accepted_at')->delete();

            return $project->invitations()->create([
                'email' => $request->validated('email'),
                'role' => $request->validated('role'),
                'token_hash' => ProjectInvitation::hashToken($token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(ProjectInvitation::LIFETIME_DAYS),
            ]);
        });

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'invite_url' => config('app.frontend_url').'/invite/'.$token,
        ], 201);
    }

    public function destroy(Project $project, ProjectInvitation $invitation): JsonResponse
    {
        $invitation->delete();

        return response()->json(null, 204);
    }

    // Public: the link itself is the secret. The invited email is not returned, since links get forwarded.
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findPending($token);

        return response()->json([
            'project_name' => $invitation->project->name,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isDemo(), 403, self::DEMO);

        $invitation = $this->findPending($token);
        abort_unless(strtolower($user->email) === strtolower($invitation->email), 403, self::WRONG_EMAIL);

        $project = $invitation->project;

        DB::transaction(function () use ($invitation, $project, $user) {
            // The conditional update makes the link single use even if it is accepted twice at once.
            $claimed = ProjectInvitation::whereKey($invitation->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);
            if ($claimed !== 1) {
                $this->invalid();
            }

            // Someone who is already a member keeps their current role.
            $project->members()->firstOrCreate(['user_id' => $user->id], ['role' => $invitation->role]);
        });

        return response()->json(['project' => ['slug' => $project->slug, 'name' => $project->name]]);
    }

    private function findPending(string $token): ProjectInvitation
    {
        return ProjectInvitation::pending()->where('token_hash', ProjectInvitation::hashToken($token))->with('project')->first()
            ?? $this->invalid();
    }

    // A fixed reply, so an unknown, expired, revoked or used link can't be told apart (not even with debug on).
    private function invalid(): never
    {
        throw new HttpResponseException(response()->json(['message' => self::INVALID], 404));
    }
}

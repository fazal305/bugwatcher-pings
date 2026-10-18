<?php

namespace App\Http\Resources;

use App\Enums\ProjectRole;
use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectMember */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->when($this->project->roleOf($viewer) === ProjectRole::Admin, $this->user->email),
            'role' => $this->role->value,
            'is_you' => $this->user_id === $viewer->id,
        ];
    }
}

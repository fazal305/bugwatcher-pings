<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectSummaryResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $projects = Project::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->with(['members' => fn ($q) => $q->where('user_id', $userId)])
            ->orderBy('name')
            ->get();

        return ProjectSummaryResource::collection($projects);
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $project = DB::transaction(function () use ($request) {
            $project = Project::create($request->validated());
            $project->members()->create(['user_id' => $request->user()->id, 'role' => ProjectRole::Admin]);

            return $project;
        });

        return new ProjectResource($project->load('members'));
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->validated());

        return new ProjectResource($project);
    }

    public function rotateKey(Project $project): ProjectResource
    {
        $project->forceFill(['public_key' => Project::newPublicKey()])->save();

        return new ProjectResource($project);
    }
}

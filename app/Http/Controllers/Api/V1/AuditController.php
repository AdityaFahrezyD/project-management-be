<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AuditService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function workspace(AuditRequest $request, Workspace $workspace): AnonymousResourceCollection
    {
        return ActivityLogResource::collection($this->audit->list($workspace, $request->validated()));
    }

    public function project(AuditRequest $request, Project $project): AnonymousResourceCollection
    {
        return ActivityLogResource::collection($this->audit->list($project, $request->validated()));
    }
}

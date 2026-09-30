<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditService
{
    public function list(Workspace|Project $scope, array $filters): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->where($scope instanceof Workspace ? 'workspace_id' : 'project_id', $scope->id)
            ->when($scope instanceof Workspace, fn ($query) => $query->whereNull('project_id'))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($filters['user_id'] ?? null, fn ($query, $value) => $query->where('user_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<=', $value))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);
    }
}

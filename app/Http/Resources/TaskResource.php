<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'is_summary' => $this->children_count > 0,
            'completion' => $this->children_count > 0 ? null : $this->status->completion(),
            'start_date' => $this->start_date?->toISOString(),
            'due_date' => $this->due_date?->toISOString(),
            'duration_days' => $this->children_count > 0 && ! $this->start_date ? null : $this->duration_days,
            'schedule_incomplete' => (bool) $this->schedule_incomplete,
            'optimistic_time' => $this->children_count > 0 ? null : $this->optimistic_time,
            'most_likely_time' => $this->children_count > 0 ? null : $this->most_likely_time,
            'pessimistic_time' => $this->children_count > 0 ? null : $this->pessimistic_time,
            'assignees' => UserResource::collection($this->whenLoaded('assignees')),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

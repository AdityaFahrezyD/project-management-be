<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DependencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'predecessor_task_id' => $this->predecessor_task_id,
            'successor_task_id' => $this->successor_task_id,
            'dependency_type' => $this->dependency_type->value,
        ];
    }
}

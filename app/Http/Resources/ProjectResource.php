<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'start_date' => $this->start_date?->toISOString(),
            'target_end_date' => $this->target_end_date?->toISOString(),
            'actual_end_date' => $this->actual_end_date?->toISOString(),
            'budget_amount' => $this->budget_amount,
            'currency' => $this->currency,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'changed_by' => $this->changed_by,
            'changed_at' => $this->changed_at?->toISOString(),
        ];
    }
}

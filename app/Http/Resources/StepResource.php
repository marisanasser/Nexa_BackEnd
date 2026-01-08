<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StepResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'guide_id' => $this->guide_id,
            'title' => $this->title,
            'description' => $this->description,
            'video_path' => $this->video_path,
            'video_url' => $this->video_url,
            'video_mime' => $this->video_mime,
            'order' => $this->order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

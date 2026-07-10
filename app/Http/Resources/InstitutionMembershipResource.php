<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'institution_id' => $this->institution_id,
            'institution_name' => $this->whenLoaded('institution', fn () => $this->institution->name),
            'role' => $this->role,
            'status' => $this->status,
        ];
    }
}

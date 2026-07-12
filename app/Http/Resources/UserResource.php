<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'phone' => $this->phone,
            'notification_preferences' => $this->notification_preferences,
            'institutions' => InstitutionMembershipResource::collection($this->whenLoaded('institutionTeachers')),
            'created_at' => $this->created_at,
        ];
    }
}

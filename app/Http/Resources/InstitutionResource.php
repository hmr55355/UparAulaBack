<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'department' => $this->department,
            'nit' => $this->nit,
            'rector' => $this->rector,
            'logo' => $this->logo,
            'grading_scale' => $this->grading_scale,
            'min_passing_grade' => $this->min_passing_grade,
            'my_role' => $this->when(
                $request->user(),
                fn () => $request->user()->membershipFor($this->id)?->role
            ),
            'created_at' => $this->created_at,
        ];
    }
}

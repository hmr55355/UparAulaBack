<?php

namespace App\Http\Requests\Institution;

use Illuminate\Foundation\Http\FormRequest;

class AssignCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ];
    }
}

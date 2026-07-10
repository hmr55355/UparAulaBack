<?php

namespace App\Http\Requests\Institution;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:50'],
            'rector' => ['nullable', 'string', 'max:255'],
            'grading_scale' => ['sometimes', 'in:1_to_10,1_to_5'],
            'min_passing_grade' => ['sometimes', 'numeric', 'min:1', 'max:10'],
            'academic_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'academic_year_start' => ['required', 'date'],
            'academic_year_end' => ['required', 'date', 'after:academic_year_start'],
        ];
    }
}

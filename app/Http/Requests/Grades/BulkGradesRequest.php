<?php

namespace App\Http\Requests\Grades;

use Illuminate\Foundation\Http\FormRequest;

class BulkGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'grades.*.grade_column_id' => ['required', 'integer', 'exists:grade_columns,id'],
            'grades.*.score' => ['nullable', 'numeric', 'min:1'],
            'grades.*.is_excused' => ['sometimes', 'boolean'],
            'grades.*.notes' => ['nullable', 'string'],
        ];
    }
}

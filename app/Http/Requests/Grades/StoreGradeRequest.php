<?php

namespace App\Http\Requests\Grades;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'grade_column_id' => ['required', 'integer', 'exists:grade_columns,id'],
            'score' => ['nullable', 'numeric', 'min:1'],
            'convention_id' => ['nullable', 'integer', 'exists:grade_conventions,id'],
            'is_excused' => ['sometimes', 'boolean'],
            'excused_reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}

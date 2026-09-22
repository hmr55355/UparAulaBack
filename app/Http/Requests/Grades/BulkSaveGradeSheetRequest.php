<?php

namespace App\Http\Requests\Grades;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class BulkSaveGradeSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'confirm_delete' => ['sometimes', 'boolean'],

            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['sometimes', 'integer'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.short_name' => ['nullable', 'string', 'max:12'],
            'sections.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'sections.*.color' => ['sometimes', 'string', 'max:20'],
            'sections.*.has_section_final' => ['sometimes', 'boolean'],
            'sections.*.section_final_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sections.*.final_calculation' => ['sometimes', 'in:weighted_avg,simple_avg,manual'],

            'sections.*.columns' => ['required', 'array'],
            'sections.*.columns.*.id' => ['sometimes', 'integer'],
            'sections.*.columns.*.name' => ['required', 'string', 'max:255'],
            'sections.*.columns.*.short_name' => ['nullable', 'string', 'max:8'],
            'sections.*.columns.*.description' => ['nullable', 'string'],
            'sections.*.columns.*.column_type' => ['required', 'in:manual,from_attendance,section_average,custom_formula,from_participation'],
            'sections.*.columns.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'sections.*.columns.*.max_score' => ['sometimes', 'numeric', 'min:1'],
            'sections.*.columns.*.date' => ['nullable', 'date'],
            'sections.*.columns.*.attendance_base_score' => ['sometimes', 'numeric'],
            'sections.*.columns.*.absence_penalty' => ['sometimes', 'numeric'],
            'sections.*.columns.*.justified_absence_penalty' => ['sometimes', 'numeric'],
            'sections.*.columns.*.formula' => ['nullable', 'string'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $sections = $this->input('sections', []);

            $sectionsWeight = array_sum(array_column($sections, 'weight'));
            if (round($sectionsWeight, 2) !== 100.0) {
                $validator->errors()->add(
                    'sections',
                    "Los pesos de las secciones suman {$sectionsWeight}% — deben sumar exactamente 100%."
                );
            }

            foreach ($sections as $index => $section) {
                $calculation = $section['final_calculation'] ?? 'weighted_avg';
                if ($calculation !== 'weighted_avg') {
                    continue;
                }

                $columnsWeight = array_sum(array_column($section['columns'] ?? [], 'weight'));
                if (round($columnsWeight, 2) !== 100.0) {
                    $name = $section['name'] ?? "Sección #{$index}";
                    $validator->errors()->add(
                        "sections.{$index}.columns",
                        "Los pesos de las columnas de \"{$name}\" suman {$columnsWeight}% — deben sumar exactamente 100%."
                    );
                }
            }
        });
    }
}

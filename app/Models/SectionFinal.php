<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionFinal extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'grade_section_id', 'group_subject_id', 'period_id',
        'section_final', 'manually_adjusted', 'adjustment_reason', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'section_final' => 'decimal:1',
            'manually_adjusted' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function gradeSection(): BelongsTo
    {
        return $this->belongsTo(GradeSection::class);
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}

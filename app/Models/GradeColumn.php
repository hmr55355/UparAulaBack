<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeColumn extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_section_id', 'group_subject_id', 'period_id', 'column_type', 'name', 'short_name',
        'description', 'weight', 'max_score', 'date', 'attendance_base_score', 'absence_penalty',
        'justified_absence_penalty', 'formula', 'is_visible', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'max_score' => 'decimal:1',
            'date' => 'date',
            'attendance_base_score' => 'decimal:1',
            'absence_penalty' => 'decimal:1',
            'justified_absence_penalty' => 'decimal:1',
            'is_visible' => 'boolean',
        ];
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

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}

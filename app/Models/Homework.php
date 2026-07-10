<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Homework extends Model
{
    use HasFactory;

    protected $table = 'homeworks';

    protected $fillable = [
        'group_subject_id', 'period_id', 'registered_by', 'title', 'description',
        'assigned_date', 'due_date', 'max_score', 'is_graded', 'grade_column_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'due_date' => 'date',
            'max_score' => 'decimal:1',
            'is_graded' => 'boolean',
        ];
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function gradeColumn(): BelongsTo
    {
        return $this->belongsTo(GradeColumn::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(HomeworkDelivery::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodFinal extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'group_subject_id', 'period_id',
        'period_final', 'is_promoted', 'manually_adjusted', 'adjustment_reason', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_final' => 'decimal:1',
            'is_promoted' => 'boolean',
            'manually_adjusted' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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

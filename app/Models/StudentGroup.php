<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'group_id', 'academic_year_id', 'enrollment_date',
        'status', 'withdrawal_date', 'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_date' => 'date',
            'withdrawal_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

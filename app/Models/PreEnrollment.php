<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prematrícula de un estudiante para el año siguiente. */
class PreEnrollment extends Model
{
    public const STATUSES = ['pendiente', 'confirmada', 'no_continua'];

    protected $fillable = [
        'institution_id', 'student_id', 'from_group_id', 'target_year', 'target_grade_level_id',
        'status', 'parent_id', 'notes', 'registered_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function targetGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'target_grade_level_id');
    }
}

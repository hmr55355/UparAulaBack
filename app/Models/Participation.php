<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Participación de un estudiante en clase. Solo existen las ya aprobadas por el docente. */
class Participation extends Model
{
    protected $fillable = ['group_subject_id', 'student_id', 'date', 'points', 'notes', 'registered_by', 'monitor_submission_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'points' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }
}

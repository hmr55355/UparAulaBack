<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un monitor habilitado por el docente en uno de sus cursos (grupo + materia). */
class CourseMonitor extends Model
{
    protected $fillable = ['group_subject_id', 'user_id', 'student_id', 'created_by', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(MonitorSubmission::class);
    }
}

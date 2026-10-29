<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id', 'academic_year_id', 'grade_level_id', 'shift_id', 'director_id', 'name', 'grade_level', 'section', 'student_count',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Ojo: no cargar con ->with('gradeLevel') para respuestas JSON — su clave
     * serializada ("grade_level") choca con la columna de texto `grade_level` y la
     * reemplazaría. El frontend resuelve el nombre del grado por `grade_level_id`.
     */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Director de grupo (docente a cargo del curso). */
    public function director(): BelongsTo
    {
        return $this->belongsTo(User::class, 'director_id');
    }

    public function isDirectedBy(User $user): bool
    {
        return $this->director_id !== null && (int) $this->director_id === (int) $user->id;
    }

    /** Ver todo el grupo (consolidado, boletines, prematrícula): admin o su director. */
    public function canBeOverseenBy(User $user): bool
    {
        return $user->isAdminOf($this->institution_id) || $this->isDirectedBy($user);
    }

    /**
     * Matricular, retirar y trasladar: siempre el admin; los docentes (director del
     * grupo o quien dicta en él) solo si la institución lo habilitó.
     */
    public function studentsCanBeManagedBy(User $user): bool
    {
        if ($user->isAdminOf($this->institution_id)) {
            return true;
        }
        if (! $this->institution->teachers_manage_students) {
            return false;
        }

        return $this->isDirectedBy($user)
            || $this->groupSubjects()->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    public function groupSubjects(): HasMany
    {
        return $this->hasMany(GroupSubject::class);
    }

    public function studentGroups(): HasMany
    {
        return $this->hasMany(StudentGroup::class);
    }

    /**
     * `student_count` es un contador guardado (se lee en muchos listados sin
     * contar cada vez). Lo mantiene al día StudentGroupObserver en cada
     * matrícula/retiro: estudiantes con matrícula activa y no eliminados.
     */
    public function refreshStudentCount(): void
    {
        $count = $this->studentGroups()->where('status', 'activo')->whereHas('student')->count();

        $this->forceFill(['student_count' => $count])->saveQuietly();
    }

    /**
     * Los otros grupos del mismo grado y año (p. ej. 1102 y 1103 para 1101): por
     * `grade_level_id`, o por el texto `grade_level` en grupos viejos sin grado.
     */
    public function scopeSameGradeAs($query, Group $group)
    {
        return $query->where('academic_year_id', $group->academic_year_id)
            ->where('id', '!=', $group->id)
            ->where(fn ($q) => $group->grade_level_id
                ? $q->where('grade_level_id', $group->grade_level_id)
                : $q->whereNull('grade_level_id')->where('grade_level', $group->grade_level));
    }
}

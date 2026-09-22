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
        'institution_id', 'academic_year_id', 'grade_level_id', 'shift_id', 'name', 'grade_level', 'section', 'student_count',
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
}

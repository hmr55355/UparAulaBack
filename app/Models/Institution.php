<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'city', 'department', 'nit', 'rector', 'logo',
        'grading_scale', 'min_passing_grade', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'min_passing_grade' => 'decimal:1',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(InstitutionTeacher::class);
    }

    public function gradeLevels(): HasMany
    {
        return $this->hasMany(GradeLevel::class)->orderBy('sort_order')->orderBy('name');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class)->orderBy('sort_order')->orderBy('name');
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** Escala de valoración institucional, de la nota más baja a la más alta. */
    public function performanceLevels(): HasMany
    {
        return $this->hasMany(PerformanceLevel::class)->orderBy('min_score');
    }

    public function gradeTemplates(): HasMany
    {
        return $this->hasMany(GradeTemplate::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Grupo de trabajo de una actividad. Su nota se copia a la de cada integrante
 * (ver WorkGroupController); la planilla sigue leyendo solo `grades`.
 */
class WorkGroup extends Model
{
    protected $fillable = ['grade_column_id', 'name', 'score', 'convention_id', 'sort_order'];

    protected function casts(): array
    {
        return ['score' => 'decimal:1'];
    }

    public function gradeColumn(): BelongsTo
    {
        return $this->belongsTo(GradeColumn::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'work_group_members')->orderBy('last_name')->orderBy('first_name');
    }

    /** Forma que usa el frontend: los integrantes como lista de ids. */
    public function toSheetArray(): array
    {
        return [
            'id' => $this->id,
            'grade_column_id' => $this->grade_column_id,
            'name' => $this->name,
            'score' => $this->score,
            'convention_id' => $this->convention_id,
            'sort_order' => $this->sort_order,
            'student_ids' => $this->members->pluck('id')->values(),
        ];
    }
}

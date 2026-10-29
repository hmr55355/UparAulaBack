<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de trabajo dentro de un conjunto. Su nota en cada actividad está en
 * `work_group_grades` y se copia a la de cada integrante (ver WorkGroupController);
 * la planilla sigue leyendo solo `grades`.
 */
class WorkGroup extends Model
{
    protected $fillable = ['work_group_set_id', 'name', 'sort_order'];

    public function set(): BelongsTo
    {
        return $this->belongsTo(WorkGroupSet::class, 'work_group_set_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'work_group_members')->orderBy('last_name')->orderBy('first_name');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(WorkGroupGrade::class);
    }
}

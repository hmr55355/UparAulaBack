<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conjunto de grupos de trabajo de un curso en un período (p. ej. "Proyecto feria").
 * Varias actividades lo comparten; cada una le pone su propia nota a cada grupo.
 */
class WorkGroupSet extends Model
{
    protected $fillable = ['group_subject_id', 'period_id', 'name'];

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(WorkGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(GradeColumn::class);
    }
}

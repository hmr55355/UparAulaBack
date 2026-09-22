<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grado escolar de la institución (Sexto, Décimo, Once…). */
class GradeLevel extends Model
{
    protected $fillable = ['institution_id', 'name', 'level', 'sort_order'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }
}

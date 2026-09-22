<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jornada (mañana, tarde, noche…): agrupa grupos y tiene su propio horario de bloques. */
class Shift extends Model
{
    protected $fillable = ['institution_id', 'name', 'sort_order'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function classBlocks(): HasMany
    {
        return $this->hasMany(ClassBlock::class)->orderBy('start_time');
    }
}

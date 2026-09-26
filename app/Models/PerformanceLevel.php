<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nivel de la escala de valoración institucional, con su equivalencia nacional. */
class PerformanceLevel extends Model
{
    protected $fillable = ['institution_id', 'name', 'national_level', 'min_score', 'max_score', 'color', 'sort_order'];

    protected function casts(): array
    {
        return ['min_score' => 'decimal:1', 'max_score' => 'decimal:1'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}

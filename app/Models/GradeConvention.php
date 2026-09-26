<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Convención de calificación del docente (NP, NA, ✓…), con nota opcional. */
class GradeConvention extends Model
{
    protected $fillable = ['user_id', 'code', 'label', 'value', 'sort_order'];

    protected function casts(): array
    {
        return ['value' => 'decimal:1'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class, 'convention_id');
    }

    /**
     * Nota que deja la convención en una columna: su valor, sin pasar del máximo
     * de la columna. null si la convención no tiene nota.
     */
    public function scoreFor(GradeColumn $column): ?float
    {
        return $this->value === null ? null : min((float) $this->value, (float) $column->max_score);
    }
}

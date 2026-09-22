<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bloque del horario de una jornada: una hora de clase o un descanso. */
class ClassBlock extends Model
{
    protected $fillable = ['shift_id', 'type', 'label', 'start_time', 'end_time', 'sort_order'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}

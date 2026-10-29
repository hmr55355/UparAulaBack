<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota de un grupo de trabajo en una actividad. */
class WorkGroupGrade extends Model
{
    protected $fillable = ['work_group_id', 'grade_column_id', 'score', 'convention_id'];

    protected function casts(): array
    {
        return ['score' => 'decimal:1'];
    }
}

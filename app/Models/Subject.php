<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['institution_id', 'name', 'code', 'color'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** Grados en los que se dicta la materia (una o varios). */
    public function gradeLevels(): BelongsToMany
    {
        return $this->belongsToMany(GradeLevel::class);
    }

    public function groupSubjects(): HasMany
    {
        return $this->hasMany(GroupSubject::class);
    }
}

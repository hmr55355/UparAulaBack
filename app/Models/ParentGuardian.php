<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a row in the `parents` table (acudientes).
 * Named ParentGuardian because `Parent` is a reserved word in PHP.
 */
class ParentGuardian extends Model
{
    use HasFactory;

    protected $table = 'parents';

    protected $fillable = [
        'institution_id', 'first_name', 'last_name', 'relationship', 'document_number',
        'phone', 'phone_alt', 'email', 'address', 'occupation', 'is_primary_contact',
    ];

    protected function casts(): array
    {
        return ['is_primary_contact' => 'boolean'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_parents', 'parent_id', 'student_id')
            ->withPivot('is_primary');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(ParentCitation::class, 'parent_id');
    }
}

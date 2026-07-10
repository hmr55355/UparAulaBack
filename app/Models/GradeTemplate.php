<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'institution_id', 'name', 'description', 'is_shared', 'sections_config',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'sections_config' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}

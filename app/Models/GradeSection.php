<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_subject_id', 'period_id', 'name', 'short_name', 'weight', 'color',
        'has_section_final', 'section_final_label', 'final_calculation', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'has_section_final' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(GradeColumn::class)->orderBy('sort_order');
    }

    public function sectionFinals(): HasMany
    {
        return $this->hasMany(SectionFinal::class);
    }
}

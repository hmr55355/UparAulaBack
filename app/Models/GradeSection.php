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

    /**
     * Reparte el 100 % por igual entre las columnas de la sección, con un decimal;
     * la última absorbe el redondeo para que la suma sea exactamente 100 (lo mismo
     * que el modo "Automático" de Configurar planilla).
     */
    public function distributeWeightsEqually(): void
    {
        $columns = $this->columns()->orderBy('sort_order')->get();
        $count = $columns->count();
        if ($count === 0) {
            return;
        }

        $share = floor(1000 / $count) / 10;
        $columns->each(function (GradeColumn $column, int $index) use ($count, $share) {
            $weight = $index === $count - 1 ? round(100 - $share * ($count - 1), 1) : $share;
            $column->update(['weight' => $weight]);
        });
    }

}

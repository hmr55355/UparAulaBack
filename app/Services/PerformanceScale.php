<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PerformanceLevel;
use Illuminate\Support\Collection;

/**
 * Escala de valoración institucional (Decreto 1290 de 2009, art. 5, compilado en
 * el Decreto 1075 de 2015, art. 2.3.3.3.3.5): cada institución define sus niveles
 * con rangos de nota y cada nivel expresa su equivalencia con la escala nacional
 * (Superior, Alto, Básico, Bajo). "Básico" es superar los desempeños necesarios;
 * "Bajo", no superarlos, así que la nota mínima para aprobar es el inicio del
 * primer nivel que no es Bajo.
 */
class PerformanceScale
{
    public const NATIONAL_LEVELS = ['bajo', 'basico', 'alto', 'superior'];

    public const NATIONAL_LABELS = [
        'bajo' => 'Desempeño Bajo',
        'basico' => 'Desempeño Básico',
        'alto' => 'Desempeño Alto',
        'superior' => 'Desempeño Superior',
    ];

    public const DEFAULT_COLORS = [
        'bajo' => '#C62828',
        'basico' => '#F9A825',
        'alto' => '#2E7D32',
        'superior' => '#1565C0',
    ];

    public static function maxFor(string $gradingScale): float
    {
        return $gradingScale === '1_to_5' ? 5.0 : 10.0;
    }

    public static function maxForInstitution(?Institution $institution): float
    {
        return self::maxFor($institution?->grading_scale ?? '1_to_10');
    }

    /**
     * Escala sugerida: las equivalencias más usadas en los SIEE (1.0–5.0 con Básico
     * desde 3.0; 1.0–10.0 con Básico desde 6.0). Si la nota mínima es otra, los
     * tramos Básico/Alto/Superior se reparten 45 % / 35 % / 20 % del resto.
     *
     * @return array<int, array{name: string, national_level: string, min_score: float, max_score: float, color: string}>
     */
    public static function defaultsFor(string $gradingScale, float $minPassing): array
    {
        $max = self::maxFor($gradingScale);
        $standard = [
            '5.0|3.0' => [3.9, 4.5],
            '10.0|6.0' => [7.9, 9.4],
        ];
        $key = number_format($max, 1).'|'.number_format($minPassing, 1);

        if (isset($standard[$key])) {
            [$basicoMax, $altoMax] = $standard[$key];
        } else {
            $span = $max - $minPassing;
            $basicoMax = round($minPassing + $span * 0.45 - 0.1, 1);
            $altoMax = round($minPassing + $span * 0.80 - 0.1, 1);
        }

        $ranges = [
            'bajo' => [1.0, round($minPassing - 0.1, 1)],
            'basico' => [$minPassing, $basicoMax],
            'alto' => [round($basicoMax + 0.1, 1), $altoMax],
            'superior' => [round($altoMax + 0.1, 1), $max],
        ];

        return collect($ranges)->map(fn ($range, $level) => [
            'name' => ucfirst(str_replace('basico', 'básico', $level)),
            'national_level' => $level,
            'min_score' => $range[0],
            'max_score' => $range[1],
            'color' => self::DEFAULT_COLORS[$level],
        ])->values()->all();
    }

    /** Nivel al que pertenece una nota (null si no hay nota o la escala no la cubre). */
    public static function levelFor(Collection $levels, ?float $score): ?PerformanceLevel
    {
        if ($score === null) {
            return null;
        }
        $score = round($score, 1);

        return $levels->first(fn (PerformanceLevel $l) => $score >= (float) $l->min_score && $score <= (float) $l->max_score);
    }

    /** Color de fondo para Excel/PDF: el color del nivel aclarado (mezcla con blanco). */
    public static function tint(string $hex, float $amount = 0.25): string
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(fn ($c) => hexdec($c), str_split($hex, 2));
        $mixed = array_map(fn ($c) => (int) round($c * $amount + 255 * (1 - $amount)), $channels);

        return strtoupper(implode('', array_map(fn ($c) => str_pad(dechex($c), 2, '0', STR_PAD_LEFT), $mixed)));
    }
}

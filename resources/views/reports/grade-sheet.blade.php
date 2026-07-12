@php
    $red = 'FFCDD2';
    $yellow = 'FFF9C4';
    $green = 'C8E6C9';
    $colorFor = function (?float $value) use ($minPassing, $red, $yellow, $green) {
        if ($value === null) return null;
        if ($value < $minPassing) return $red;
        if ($value < $minPassing + 1) return $yellow;
        return $green;
    };
    $periodFinalValues = [];
    foreach ($students as $student) {
        $pf = $periodFinalsByStudent->get($student->id)?->period_final;
        if ($pf !== null) $periodFinalValues[] = (float) $pf;
    }
    $approved = count(array_filter($periodFinalValues, fn ($v) => $v >= (float) $minPassing));
    $total = count($periodFinalValues);
    $average = $total > 0 ? array_sum($periodFinalValues) / $total : null;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 15px; margin: 0 0 4px 0; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 3px 5px; text-align: center; }
        th.section { color: #fff; font-weight: bold; }
        th.name-col, td.name-col { text-align: left; }
        .footer { margin-top: 12px; }
        .footer p { margin: 2px 0; font-weight: bold; }
    </style>
</head>
<body>
    @if ($logoPath)
        <img src="file://{{ $logoPath }}" style="height: 50px; float: left; margin-right: 10px;">
    @endif
    <h1>{{ $institution->name }}</h1>
    <p class="meta">Planilla de Calificaciones — {{ $groupSubject->subject->name }} — {{ $groupSubject->group->name }} — {{ $period->name }}</p>
    <p class="meta">Docente: {{ $groupSubject->teacher->name ?? '' }}</p>

    <table>
        <thead>
            <tr>
                <th rowspan="2" class="name-col">Estudiante</th>
                @foreach ($sections as $section)
                    @php $span = $section->columns->count() + ($section->has_section_final ? 1 : 0); @endphp
                    @if ($span > 0)
                        <th colspan="{{ $span }}" class="section" style="background:#{{ ltrim($section->color ?? '1565C0', '#') }}">{{ $section->name }}</th>
                    @endif
                @endforeach
                <th rowspan="2">Def Total</th>
            </tr>
            <tr>
                @foreach ($sections as $section)
                    @foreach ($section->columns as $column)
                        <th>{{ $column->short_name ?: $column->name }}</th>
                    @endforeach
                    @if ($section->has_section_final)
                        <th>{{ $section->section_final_label ?: 'Def' }}</th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($students as $student)
                @php
                    $grades = ($gradesByStudent->get($student->id) ?? collect())->keyBy('grade_column_id');
                    $sectionFinals = ($sectionFinalsByStudent->get($student->id) ?? collect())->keyBy('grade_section_id');
                    $periodFinal = $periodFinalsByStudent->get($student->id)?->period_final;
                @endphp
                <tr>
                    <td class="name-col">{{ $student->last_name }} {{ $student->first_name }}</td>
                    @foreach ($sections as $section)
                        @foreach ($section->columns as $column)
                            @php $score = $grades->get($column->id)?->score; @endphp
                            <td @if ($column->column_type !== 'manual') style="background:#EEEEEE" @endif>{{ $score !== null ? number_format($score, 1) : '—' }}</td>
                        @endforeach
                        @if ($section->has_section_final)
                            @php $final = $sectionFinals->get($section->id)?->section_final; @endphp
                            <td @if ($final !== null) style="background:#{{ $colorFor((float) $final) }}" @endif>{{ $final !== null ? number_format($final, 1) : '—' }}</td>
                        @endif
                    @endforeach
                    <td @if ($periodFinal !== null) style="background:#{{ $colorFor((float) $periodFinal) }};font-weight:bold" @endif>{{ $periodFinal !== null ? number_format($periodFinal, 1) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>Promedio del grupo: {{ $average !== null ? number_format($average, 1) : '—' }}</p>
        <p>% Aprobados: {{ $total > 0 ? round(($approved / $total) * 100) : '—' }}%</p>
        <p>Máxima: {{ $periodFinalValues !== [] ? number_format(max($periodFinalValues), 1) : '—' }}</p>
        <p>Mínima: {{ $periodFinalValues !== [] ? number_format(min($periodFinalValues), 1) : '—' }}</p>
    </div>

    <p style="margin-top: 30px;">Firma del docente: _____________________________</p>
</body>
</html>

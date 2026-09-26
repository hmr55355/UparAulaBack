<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px 0; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #d9d9d9; }
        .promoted { color: #2E7D32; }
        .not-promoted { color: #C62828; }
    </style>
</head>
<body>
    @if ($logoPath)
        <img src="file://{{ $logoPath }}" style="height: 50px; float: left; margin-right: 10px;">
    @endif
    <h1>{{ $student->institution->name }}</h1>
    <p class="meta">Boletín individual — {{ $period->name }}</p>
    <p class="meta">Estudiante: {{ $student->last_name }} {{ $student->first_name }}</p>
    @if ($group)
        <p class="meta">Grupo: {{ $group->name }}</p>
    @endif

    <h2>Notas por materia</h2>
    <table>
        <thead><tr><th>Materia</th><th>Definitiva del período</th><th>Desempeño</th><th>Escala nacional</th></tr></thead>
        <tbody>
            @forelse ($grades as $g)
                <tr>
                    <td>{{ $g['subject'] }}</td>
                    <td @if ($g['color']) style="background:#{{ $g['color'] }}" @endif>{{ $g['period_final'] !== null ? number_format((float) $g['period_final'], 1) : '—' }}</td>
                    <td>{{ $g['level_name'] ?? '—' }}</td>
                    <td>{{ $g['national_level'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Sin materias registradas para este grupo.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($levels->isNotEmpty())
        <p class="meta">
            Escala de valoración institucional:
            @foreach ($levels as $level)
                {{ $level->name }} {{ number_format((float) $level->min_score, 1) }}–{{ number_format((float) $level->max_score, 1) }} ({{ $nationalLabels[$level->national_level] }})@if (! $loop->last); @endif
            @endforeach
        </p>
    @endif

    <h2>Asistencia del período</h2>
    <table>
        <thead><tr><th>Presente</th><th>Ausente injustificado</th><th>Ausente justificado</th><th>Tarde</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $attendanceCounts['presente'] ?? 0 }}</td>
                <td>{{ $attendanceCounts['ausente_injustificado'] ?? 0 }}</td>
                <td>{{ $attendanceCounts['ausente_justificado'] ?? 0 }}</td>
                <td>{{ $attendanceCounts['tarde'] ?? 0 }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Comportamiento general</h2>
    <table>
        <thead><tr><th>Positivas</th><th>Negativas</th><th>Informativas</th><th>Acuerdos</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $behaviorCounts['positiva'] ?? 0 }}</td>
                <td>{{ $behaviorCounts['negativa'] ?? 0 }}</td>
                <td>{{ $behaviorCounts['informativa'] ?? 0 }}</td>
                <td>{{ $behaviorCounts['acuerdo'] ?? 0 }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Observaciones del docente</h2>
    @forelse ($observations as $observation)
        <p class="meta">{{ $observation->date->format('d/m/Y') }} — {{ $observation->content }}</p>
    @empty
        <p class="meta">Sin observaciones registradas para este período.</p>
    @endforelse

    <p style="margin-top: 40px;">Firma del docente: _____________________________</p>
</body>
</html>

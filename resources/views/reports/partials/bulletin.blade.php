{{-- Un boletín (una página). Variables: institution, logoPath, levels, nationalLabels + las de GroupOverviewService::bulletin(). --}}
    @if ($logoPath)
        <img src="file://{{ $logoPath }}" style="height: 50px; float: left; margin-right: 10px;">
    @endif
    <h1>{{ $institution->name }}</h1>
    <p class="meta">Boletín individual — {{ $period->name }}</p>
    <p class="meta">Estudiante: {{ $student->last_name }} {{ $student->first_name }}</p>
    @if ($group)
        <p class="meta">Grupo: {{ $group->name }}@if ($group->director) · Director(a) de grupo: {{ $group->director->name }}@endif</p>
    @endif
    @if (! empty($summary) && $summary['average'] !== null)
        <p class="meta">
            Promedio del período: <strong>{{ number_format($summary['average'], 2) }}</strong>
            @if ($summary['rank']) · Puesto en el grupo: <strong>{{ $summary['rank'] }}</strong>@endif
            · Materias con desempeño bajo: <strong>{{ $summary['failed'] }}</strong>
        </p>
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

    <table style="margin-top: 40px; border: none;">
        <tr>
            <td style="border: none;">_____________________________<br>Director(a) de grupo</td>
            <td style="border: none;">_____________________________<br>Acudiente</td>
        </tr>
    </table>

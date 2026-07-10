<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\GroupSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClassScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = ClassSchedule::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->with(['groupSubject.group', 'groupSubject.subject']);

        if ($request->filled('dayOfWeek')) {
            $query->where('day_of_week', $request->dayOfWeek);
        }

        $blocks = $query->orderBy('day_of_week')->orderBy('start_time')->get();

        return response()->json(['data' => $blocks]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('view', $groupSubject);

        $overlap = $this->findOwnOverlap($request->user()->id, $validated);
        if ($overlap && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => "Este bloque se solapa con \"{$overlap->groupSubject->subject->name}\" el mismo día. Confirma para guardarlo de todas formas.",
                'requires_confirmation' => true,
            ], 409);
        }

        $warnings = $this->findClassroomWarnings($request->user()->id, $validated);

        $block = ClassSchedule::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $block->load('groupSubject.group', 'groupSubject.subject'), 'warnings' => $warnings], 201);
    }

    public function update(Request $request, ClassSchedule $classSchedule)
    {
        $this->authorize('view', $classSchedule->groupSubject);
        abort_unless($classSchedule->user_id === $request->user()->id, 403);

        $validated = $this->validatePayload($request);

        $overlap = $this->findOwnOverlap($request->user()->id, $validated, excludeId: $classSchedule->id);
        if ($overlap && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => "Este bloque se solapa con \"{$overlap->groupSubject->subject->name}\" el mismo día. Confirma para guardarlo de todas formas.",
                'requires_confirmation' => true,
            ], 409);
        }

        $warnings = $this->findClassroomWarnings($request->user()->id, $validated, excludeId: $classSchedule->id);

        $classSchedule->update($validated);

        return response()->json(['data' => $classSchedule->fresh(['groupSubject.group', 'groupSubject.subject']), 'warnings' => $warnings]);
    }

    public function destroy(Request $request, ClassSchedule $classSchedule)
    {
        abort_unless($classSchedule->user_id === $request->user()->id, 403);

        $classSchedule->delete();

        return response()->json(['message' => 'Bloque eliminado.']);
    }

    public function duplicateDay(Request $request)
    {
        $validated = $request->validate([
            'from_day' => ['required', 'integer', 'min:1', 'max:6'],
            'to_day' => ['required', 'integer', 'min:1', 'max:6', 'different:from_day'],
        ]);

        $blocks = ClassSchedule::where('user_id', $request->user()->id)
            ->where('day_of_week', $validated['from_day'])
            ->where('is_active', true)
            ->get();

        $created = $blocks->map(function (ClassSchedule $block) use ($validated) {
            return ClassSchedule::create([
                'group_subject_id' => $block->group_subject_id,
                'user_id' => $block->user_id,
                'day_of_week' => $validated['to_day'],
                'start_time' => $block->start_time,
                'end_time' => $block->end_time,
                'classroom' => $block->classroom,
                'block_label' => $block->block_label,
                'academic_year_id' => $block->academic_year_id,
            ]);
        });

        return response()->json(['data' => $created]);
    }

    /**
     * Detección de "clase actual" (nota #29): compara la hora del servidor con los
     * bloques activos del docente para el día de hoy.
     */
    public function currentClass(Request $request)
    {
        $now = Carbon::now();
        $blocks = ClassSchedule::where('user_id', $request->user()->id)
            ->where('day_of_week', $now->isoWeekday())
            ->where('is_active', true)
            ->with('groupSubject.group', 'groupSubject.subject')
            ->orderBy('start_time')
            ->get();

        $currentTime = $now->format('H:i:s');

        $inProgress = $blocks->first(
            fn (ClassSchedule $b) => $b->start_time <= $currentTime && $b->end_time >= $currentTime
        );
        if ($inProgress) {
            return response()->json(['status' => 'en_curso', 'block' => $inProgress]);
        }

        $upcoming = $blocks
            ->filter(fn (ClassSchedule $b) => $b->start_time > $currentTime)
            ->sortBy('start_time')
            ->first();

        if ($upcoming) {
            $nowMinutes = $now->hour * 60 + $now->minute;
            [$startHour, $startMinute] = explode(':', $upcoming->start_time);
            $startMinutes = ((int) $startHour) * 60 + (int) $startMinute;
            $minutesUntil = $startMinutes - $nowMinutes;

            if ($minutesUntil <= 15) {
                return response()->json(['status' => 'proxima', 'block' => $upcoming, 'minutes_until' => $minutesUntil]);
            }

            return response()->json(['status' => 'sin_clase_ahora', 'block' => $upcoming]);
        }

        return response()->json(['status' => 'sin_clase_ahora', 'block' => null]);
    }

    public function today(Request $request)
    {
        $now = Carbon::now();

        $blocks = ClassSchedule::where('user_id', $request->user()->id)
            ->where('day_of_week', $now->isoWeekday())
            ->where('is_active', true)
            ->with('groupSubject.group', 'groupSubject.subject')
            ->orderBy('start_time')
            ->get();

        return response()->json(['data' => $blocks]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'classroom' => ['nullable', 'string', 'max:255'],
            'block_label' => ['nullable', 'string', 'max:255'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);
    }

    private function findOwnOverlap(int $userId, array $data, ?int $excludeId = null): ?ClassSchedule
    {
        return ClassSchedule::where('user_id', $userId)
            ->where('day_of_week', $data['day_of_week'])
            ->where('is_active', true)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->with('groupSubject.subject')
            ->first();
    }

    /**
     * @return string[] warning messages — never blocks, just informs (nota del prompt).
     */
    private function findClassroomWarnings(int $userId, array $data, ?int $excludeId = null): array
    {
        if (empty($data['classroom'])) {
            return [];
        }

        $conflicts = ClassSchedule::where('classroom', $data['classroom'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('user_id', '!=', $userId)
            ->where('is_active', true)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->with('teacher')
            ->get();

        return $conflicts->map(
            fn (ClassSchedule $c) => "El salón \"{$data['classroom']}\" también está asignado a {$c->teacher->name} el mismo horario."
        )->all();
    }
}

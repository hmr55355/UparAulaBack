<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\ResourceLoan;
use App\Models\Student;
use App\Models\StudentGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Préstamo de recursos a estudiantes y docentes. Cada docente maneja los que
 * registró; el administrador ve y maneja todos los de la institución.
 */
class ResourceLoanController extends Controller
{
    private const WITH = ['student:id,first_name,last_name', 'borrowerUser:id,name', 'group:id,name', 'lender:id,name'];

    public function index(Request $request)
    {
        $institution = $this->institution($request);
        $user = $request->user();
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['activos', 'vencidos', 'devueltos', 'todos'])],
            'category' => ['nullable', Rule::in(array_keys(ResourceLoan::CATEGORIES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $base = ResourceLoan::where('institution_id', $institution->id)
            ->when(! $user->isAdminOf($institution->id), fn ($q) => $q->where('registered_by', $user->id));

        $query = (clone $base)
            ->when($validated['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('item_name', 'like', "%{$term}%")
                ->orWhere('item_code', 'like', "%{$term}%")
                ->orWhereHas('student', fn ($s) => $s->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"))
                ->orWhereHas('borrowerUser', fn ($u) => $u->where('name', 'like', "%{$term}%"))));

        // Sin when(): Laravel ejecuta por su cuenta una closure pasada como condición.
        match ($validated['status'] ?? 'activos') {
            'activos' => $query->where('status', 'prestado'),
            'vencidos' => $query->overdue(),
            'devueltos' => $query->whereIn('status', ['devuelto', 'perdido']),
            default => null,
        };

        $loans = $query->with(self::WITH)
            ->orderByRaw("status = 'prestado' desc")
            ->orderBy('due_date')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $loans,
            'summary' => [
                'active' => (clone $base)->where('status', 'prestado')->count(),
                'overdue' => (clone $base)->overdue()->count(),
                'returned' => (clone $base)->whereIn('status', ['devuelto', 'perdido'])->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $institution = $this->institution($request);
        $validated = $request->validate($this->rules());
        $borrower = $this->resolveBorrower($institution, $validated);

        $loan = ResourceLoan::create([
            ...$validated,
            ...$borrower,
            'institution_id' => $institution->id,
            'registered_by' => $request->user()->id,
            'quantity' => $validated['quantity'] ?? 1,
            'status' => 'prestado',
        ]);

        return response()->json(['data' => $loan->load(self::WITH)], 201);
    }

    public function update(Request $request, ResourceLoan $resourceLoan)
    {
        $this->authorizeManage($request, $resourceLoan);

        $validated = $request->validate($this->rules(partial: true));
        $this->assertDates(
            $validated['loan_date'] ?? $resourceLoan->loan_date->toDateString(),
            $validated['due_date'] ?? $resourceLoan->due_date->toDateString()
        );
        // El prestatario no se cambia al editar: sería otro préstamo.
        $resourceLoan->update(collect($validated)->except(['student_id', 'borrower_user_id'])->all());

        return response()->json(['data' => $resourceLoan->fresh()->load(self::WITH)]);
    }

    /** Registrar la devolución (o marcarlo como perdido). */
    public function markReturned(Request $request, ResourceLoan $resourceLoan)
    {
        $this->authorizeManage($request, $resourceLoan);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['devuelto', 'perdido'])],
            'returned_at' => ['nullable', 'date', 'before_or_equal:today'],
            'return_condition' => ['nullable', Rule::in(ResourceLoan::RETURN_CONDITIONS)],
            'return_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $lost = $validated['status'] === 'perdido';
        $resourceLoan->update([
            'status' => $validated['status'],
            'returned_at' => $lost ? null : ($validated['returned_at'] ?? now()->toDateString()),
            'return_condition' => $lost ? null : ($validated['return_condition'] ?? 'bueno'),
            'return_notes' => $validated['return_notes'] ?? null,
        ]);

        return response()->json(['data' => $resourceLoan->fresh()->load(self::WITH)]);
    }

    /** Deshacer una devolución registrada por error: vuelve a "prestado". */
    public function reopen(Request $request, ResourceLoan $resourceLoan)
    {
        $this->authorizeManage($request, $resourceLoan);

        $resourceLoan->update(['status' => 'prestado', 'returned_at' => null, 'return_condition' => null, 'return_notes' => null]);

        return response()->json(['data' => $resourceLoan->fresh()->load(self::WITH)]);
    }

    public function destroy(Request $request, ResourceLoan $resourceLoan)
    {
        $this->authorizeManage($request, $resourceLoan);
        $resourceLoan->delete();

        return response()->json(['message' => 'Préstamo eliminado.']);
    }

    /**
     * Alertas de no devolución para el inicio y el perfil: los vencidos que yo
     * entregué (todos, si soy admin) y lo que yo tengo en préstamo.
     */
    public function alerts(Request $request)
    {
        $institution = $this->institution($request);
        $user = $request->user();

        $lent = ResourceLoan::where('institution_id', $institution->id)
            ->when(! $user->isAdminOf($institution->id), fn ($q) => $q->where('registered_by', $user->id))
            ->overdue();

        return response()->json([
            'overdue_lent' => $lent->count(),
            'borrowed' => ResourceLoan::where('institution_id', $institution->id)
                ->where('borrower_user_id', $user->id)
                ->where('status', 'prestado')
                ->with('lender:id,name')
                ->orderBy('due_date')
                ->get(),
        ]);
    }

    /** Docentes activos de la institución, para elegir a quién se le presta. */
    public function teachers(Request $request)
    {
        $institution = $this->institution($request);

        $teachers = InstitutionTeacher::where('institution_id', $institution->id)
            ->where('status', 'active')
            ->with('user:id,name')
            ->get()
            ->map(fn (InstitutionTeacher $t) => ['id' => $t->user_id, 'name' => $t->user?->name])
            ->filter(fn ($t) => $t['name'])
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $teachers]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'student_id' => ['nullable', 'integer'],
            'borrower_user_id' => ['nullable', 'integer'],
            'category' => [$required, Rule::in(array_keys(ResourceLoan::CATEGORIES))],
            'item_name' => [$required, 'string', 'max:150'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'item_code' => ['nullable', 'string', 'max:60'],
            'loan_date' => [$required, 'date', 'before_or_equal:today'],
            'due_date' => [$required, 'date', ...($partial ? [] : ['after_or_equal:loan_date'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function assertDates(string $loanDate, string $dueDate): void
    {
        if ($dueDate < $loanDate) {
            throw ValidationException::withMessages(['due_date' => ['La fecha de devolución no puede ser anterior a la del préstamo.']]);
        }
    }

    /** Exactamente un prestatario, y de esta institución. @return array{student_id: ?int, borrower_user_id: ?int, group_id: ?int} */
    private function resolveBorrower(Institution $institution, array $validated): array
    {
        $studentId = $validated['student_id'] ?? null;
        $userId = $validated['borrower_user_id'] ?? null;

        if ((bool) $studentId === (bool) $userId) {
            throw ValidationException::withMessages(['student_id' => ['Elige a quién se le presta: un estudiante o un docente.']]);
        }

        if ($studentId) {
            $student = Student::where('institution_id', $institution->id)->find($studentId);
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => ['Ese estudiante no es de esta institución.']]);
            }
            $groupId = StudentGroup::where('student_id', $student->id)->where('status', 'activo')->latest('id')->value('group_id');

            return ['student_id' => $student->id, 'borrower_user_id' => null, 'group_id' => $groupId];
        }

        $isMember = InstitutionTeacher::where('institution_id', $institution->id)
            ->where('user_id', $userId)->where('status', 'active')->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['borrower_user_id' => ['Ese docente no es de esta institución.']]);
        }

        return ['student_id' => null, 'borrower_user_id' => (int) $userId, 'group_id' => null];
    }

    private function authorizeManage(Request $request, ResourceLoan $loan): void
    {
        abort_unless(
            $loan->canBeManagedBy($request->user()),
            403,
            'Solo quien registró el préstamo o un administrador puede modificarlo.'
        );
    }

    private function institution(Request $request): Institution
    {
        return $request->user()->institutionTeachers()->where('status', 'active')->firstOrFail()->institution;
    }
}

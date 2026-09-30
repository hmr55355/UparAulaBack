<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\CopyCharge;
use App\Models\Group;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentCopyPayment;
use Illuminate\Http\Request;

class CopyChargeController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupId' => ['required', 'integer', 'exists:groups,id'],
            'periodId' => ['nullable', 'integer', 'exists:periods,id'],
        ]);

        $group = Group::findOrFail($request->groupId);
        $this->authorize('view', $group->institution);

        $charges = CopyCharge::where('group_id', $group->id)
            ->when($request->periodId, fn ($q, $periodId) => $q->where('period_id', $periodId))
            ->withCount([
                'payments as paid_count' => fn ($q) => $q->where('status', 'pagado'),
                'payments as total_students',
            ])
            ->withSum('payments as collected_amount', 'amount_paid')
            ->orderByDesc('charge_date')
            ->get()
            ->map(function (CopyCharge $charge) {
                // Cada estudiante debe individualmente el total_amount completo
                // (el cobro es "cantidad × precio" de SU PROPIA copia, no un monto
                // compartido entre todo el grupo) — así que lo recaudable total es
                // total_amount × cantidad de estudiantes, no total_amount a secas.
                $collected = $charge->collected_amount ?? 0;
                $charge->pending_amount = ($charge->total_amount * $charge->total_students) - $collected;

                return $charge;
            });

        return response()->json(['data' => $charges]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'charge_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $group = Group::findOrFail($validated['group_id']);
        $this->authorize('view', $group->institution);

        $charge = CopyCharge::create([
            ...$validated,
            'total_amount' => $validated['quantity'] * $validated['unit_price'],
            'registered_by' => $request->user()->id,
        ]);

        $studentIds = $group->studentGroups()->where('status', 'activo')->pluck('student_id');
        foreach ($studentIds as $studentId) {
            StudentCopyPayment::create([
                'copy_charge_id' => $charge->id,
                'student_id' => $studentId,
                'registered_by' => $request->user()->id,
                'status' => 'debe',
                'amount_paid' => 0,
            ]);
        }

        return response()->json(['data' => $charge], 201);
    }

    public function update(Request $request, CopyCharge $copyCharge)
    {
        $this->authorize('view', $copyCharge->group->institution);

        $validated = $request->validate([
            'description' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'charge_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $quantity = $validated['quantity'] ?? $copyCharge->quantity;
        $unitPrice = $validated['unit_price'] ?? $copyCharge->unit_price;

        $newTotal = $quantity * $unitPrice;
        $totalChanged = round((float) $newTotal, 2) !== round((float) $copyCharge->total_amount, 2);

        $copyCharge->update([
            ...$validated,
            'total_amount' => $newTotal,
        ]);

        // Con otro total, "pagado" y "pago parcial" se reevalúan contra lo abonado
        // (quien pagó el total viejo puede quedar debiendo la diferencia). Los
        // exonerados no se tocan.
        if ($totalChanged) {
            $copyCharge->payments()->where('status', '!=', 'exonerado')->get()
                ->each(function (StudentCopyPayment $payment) use ($newTotal) {
                    $paid = (float) $payment->amount_paid;
                    $status = $paid <= 0 ? 'debe' : ($paid >= (float) $newTotal ? 'pagado' : 'pago_parcial');
                    if ($status !== $payment->status) {
                        $payment->update(['status' => $status]);
                    }
                });
        }

        return response()->json(['data' => $copyCharge->fresh()]);
    }

    public function destroy(Request $request, CopyCharge $copyCharge)
    {
        $this->authorize('view', $copyCharge->group->institution);

        // Mismo patrón que borrar una tarea con notas: si ya hay dinero recaudado,
        // se pide confirmación explícita antes de perder ese registro.
        $collected = (float) $copyCharge->payments()->sum('amount_paid');
        if ($collected > 0 && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'Este cobro ya tiene $'.number_format($collected, 0, ',', '.').' recaudados. Confirma la eliminación.',
                'requires_confirmation' => true,
            ], 409);
        }

        $copyCharge->delete();

        return response()->json(['message' => 'Cobro eliminado.']);
    }

    public function payments(CopyCharge $copyCharge)
    {
        $this->authorize('view', $copyCharge->group->institution);

        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $copyCharge->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get();

        $payments = StudentCopyPayment::where('copy_charge_id', $copyCharge->id)->get()->keyBy('student_id');

        return response()->json([
            'charge' => $copyCharge,
            'students' => $students,
            'payments' => $payments,
        ]);
    }

    public function bulkPayments(Request $request, CopyCharge $copyCharge)
    {
        $this->authorize('view', $copyCharge->group->institution);

        $validated = $request->validate([
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'payments.*.status' => ['required', 'in:debe,pago_parcial,pagado,exonerado'],
            'payments.*.amount_paid' => ['sometimes', 'numeric', 'min:0'],
            'payments.*.payment_date' => ['nullable', 'date'],
            'payments.*.exoneration_reason' => ['nullable', 'string'],
            'payments.*.notes' => ['nullable', 'string'],
        ]);

        $saved = collect($validated['payments'])->map(function (array $item) use ($copyCharge, $request) {
            // "debe" también sirve para deshacer un pago marcado por error: sin monto,
            // sin fecha y sin motivo de exoneración, como si nunca se hubiera registrado.
            $owes = $item['status'] === 'debe';

            return StudentCopyPayment::updateOrCreate(
                ['copy_charge_id' => $copyCharge->id, 'student_id' => $item['student_id']],
                [
                    'registered_by' => $request->user()->id,
                    'status' => $item['status'],
                    'amount_paid' => $owes ? 0 : ($item['amount_paid'] ?? ($item['status'] === 'pagado' ? $copyCharge->total_amount : 0)),
                    'payment_date' => $owes ? null : ($item['payment_date'] ?? now()->toDateString()),
                    'exoneration_reason' => $owes ? null : ($item['exoneration_reason'] ?? null),
                    'notes' => $item['notes'] ?? null,
                ]
            );
        });

        return response()->json(['data' => $saved, 'count' => $saved->count()], 201);
    }

    public function updatePaymentSingle(Request $request, StudentCopyPayment $payment)
    {
        $this->authorize('view', $payment->copyCharge->group->institution);

        $validated = $request->validate([
            'status' => ['required', 'in:debe,pago_parcial,pagado,exonerado'],
            'amount_paid' => ['sometimes', 'numeric', 'min:0'],
            'payment_date' => ['nullable', 'date'],
            'exoneration_reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment->update([
            ...$validated,
            'registered_by' => $request->user()->id,
            'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
        ]);

        return response()->json(['data' => $payment->fresh()]);
    }

    /**
     * Resumen general de copias (`/copies/summary` del spec): dispara el mismo
     * flujo en cola de ReportController — el reporte cruzado de cobros del
     * grupo/período vive conceptualmente aquí, no en el controller de reportes.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
        ]);

        $group = Group::findOrFail($validated['group_id']);
        $this->authorize('view', $group->institution);

        $validated['format'] = 'excel';

        $report = Report::create([
            'user_id' => $request->user()->id,
            'institution_id' => $group->institution_id,
            'type' => 'copies_summary',
            'format' => 'excel',
            'params' => $validated,
            'status' => 'pending',
        ]);

        GenerateReportJob::dispatch($report->id);

        return response()->json(['data' => ['id' => $report->id]], 202);
    }
}

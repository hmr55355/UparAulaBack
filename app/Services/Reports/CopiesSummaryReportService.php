<?php

namespace App\Services\Reports;

use App\Models\CopyCharge;
use App\Models\Group;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentCopyPayment;
use App\Models\StudentGroup;
use Illuminate\Support\Str;

/**
 * Resumen general de copias (`/copies/summary`): tabla cruzada de todos los
 * cobros del grupo/período — una columna por cobro, con color por estado de
 * pago de cada estudiante (mismos colores que PAYMENT_STATUS_BADGE en el
 * frontend), más Total debe/pagado/saldo. Solo Excel, per spec.
 */
class CopiesSummaryReportService
{
    private const STATUS_LABEL = [
        'debe' => 'Debe',
        'pago_parcial' => 'Pago parcial',
        'pagado' => 'Pagado',
        'exonerado' => 'Exonerado',
    ];

    private const STATUS_COLOR = [
        'debe' => 'FFCDD2',
        'pago_parcial' => 'FFF9C4',
        'pagado' => 'C8E6C9',
        'exonerado' => 'E0E0E0',
    ];

    public function generate(Report $report): string
    {
        $params = $report->params;
        $group = Group::with('institution')->findOrFail($params['group_id']);
        $periodId = $params['period_id'] ?? null;

        $charges = CopyCharge::where('group_id', $group->id)
            ->when($periodId, fn ($q, $p) => $q->where('period_id', $p))
            ->orderBy('charge_date')
            ->get();

        $studentIds = StudentGroup::where('group_id', $group->id)->where('status', 'activo')->pluck('student_id');
        $students = Student::whereIn('id', $studentIds)->orderBy('last_name')->orderBy('first_name')->get();

        $payments = StudentCopyPayment::whereIn('copy_charge_id', $charges->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $headers = array_merge(['Estudiante'], $charges->map(fn (CopyCharge $c) => $c->description)->all(), ['Total debe', 'Total pagado', 'Saldo']);

        $rows = [];
        $cellColors = [];
        foreach ($students as $student) {
            $studentPayments = $payments->get($student->id) ?? collect();
            $row = ["{$student->last_name} {$student->first_name}"];
            $colors = [null];
            $totalDebe = 0.0;
            $totalPagado = 0.0;

            foreach ($charges as $charge) {
                $payment = $studentPayments->firstWhere('copy_charge_id', $charge->id);
                $status = $payment->status ?? 'debe';
                $amountPaid = $payment ? (float) $payment->amount_paid : 0.0;

                if ($status !== 'exonerado') {
                    $totalDebe += (float) $charge->total_amount;
                }
                $totalPagado += $amountPaid;

                $row[] = self::STATUS_LABEL[$status] ?? $status;
                $colors[] = self::STATUS_COLOR[$status] ?? null;
            }

            $row[] = number_format($totalDebe, 0);
            $row[] = number_format($totalPagado, 0);
            $row[] = number_format($totalDebe - $totalPagado, 0);
            $colors[] = null;
            $colors[] = null;
            $colors[] = null;

            $rows[] = $row;
            $cellColors[] = $colors;
        }

        $title = "Resumen General de Copias — {$group->name}";
        $metaLines = ["Institución: {$group->institution->name}"];

        $relativePath = "reports/{$group->institution_id}/{$report->user_id}/".Str::uuid().'.xlsx';

        ExcelTableWriter::write(
            $relativePath,
            $title,
            $metaLines,
            $headers,
            $rows,
            fn (int $rowIndex, int $colIndex, mixed $value) => $cellColors[$rowIndex][$colIndex] ?? null
        );

        return $relativePath;
    }
}

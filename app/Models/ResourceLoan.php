<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Préstamo de un recurso (libro, material, equipo) a un estudiante o a un docente. */
class ResourceLoan extends Model
{
    public const CATEGORIES = [
        'libro' => 'Libro',
        'material' => 'Material didáctico',
        'portatil' => 'Portátil o tablet',
        'tecnologia' => 'Material tecnológico',
        'laboratorio' => 'Laboratorio',
        'audiovisual' => 'Audiovisual',
        'deportivo' => 'Deportivo',
        'musical' => 'Instrumento musical',
        'otro' => 'Otro',
    ];

    public const STATUSES = ['prestado', 'devuelto', 'perdido'];

    public const RETURN_CONDITIONS = ['bueno', 'danado', 'incompleto'];

    protected $fillable = [
        'institution_id', 'registered_by', 'student_id', 'borrower_user_id', 'group_id',
        'category', 'item_name', 'quantity', 'item_code', 'loan_date', 'due_date',
        'status', 'returned_at', 'return_condition', 'notes', 'return_notes',
    ];

    protected $appends = ['is_overdue', 'days_overdue'];

    protected function casts(): array
    {
        return ['loan_date' => 'date', 'due_date' => 'date', 'returned_at' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function borrowerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** Sin devolver y con la fecha de devolución ya pasada. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'prestado')->whereDate('due_date', '<', now()->toDateString());
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'prestado' && $this->due_date && $this->due_date->toDateString() < now()->toDateString();
    }

    public function getDaysOverdueAttribute(): int
    {
        return $this->is_overdue ? (int) $this->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    /** Lo administra quien lo registró o un administrador de la institución. */
    public function canBeManagedBy(User $user): bool
    {
        return (int) $this->registered_by === (int) $user->id || $user->isAdminOf($this->institution_id);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCopyPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'copy_charge_id', 'student_id', 'registered_by', 'status',
        'amount_paid', 'payment_date', 'exoneration_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function copyCharge(): BelongsTo
    {
        return $this->belongsTo(CopyCharge::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}

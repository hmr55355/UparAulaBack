<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'homework_id', 'student_id', 'status', 'delivery_date', 'score', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'score' => 'decimal:1',
        ];
    }

    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

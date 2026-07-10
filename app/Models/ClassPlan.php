<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_subject_id', 'period_id', 'class_schedule_id', 'registered_by', 'date', 'topic',
        'objectives', 'activities', 'resources', 'what_was_done', 'pending_for_next_class',
        'attendance_note', 'homework_assigned', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParentCitation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_id', 'parent_id', 'group_id', 'registered_by', 'behavior_annotation_id',
        'citation_type', 'reason', 'scheduled_date', 'location', 'status',
        'notification_method', 'notification_date', 'outcome', 'commitments',
        'follow_up_date', 'attachments_note',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'datetime',
            'notification_date' => 'datetime',
            'follow_up_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentGuardian::class, 'parent_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function behaviorAnnotation(): BelongsTo
    {
        return $this->belongsTo(BehaviorAnnotation::class);
    }
}

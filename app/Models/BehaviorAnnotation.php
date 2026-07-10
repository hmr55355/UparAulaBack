<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BehaviorAnnotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_id', 'group_subject_id', 'group_id', 'registered_by', 'date', 'type', 'category',
        'title', 'description', 'action_taken', 'requires_parent_contact',
        'parent_contacted', 'parent_contact_date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'requires_parent_contact' => 'boolean',
            'parent_contacted' => 'boolean',
            'parent_contact_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}

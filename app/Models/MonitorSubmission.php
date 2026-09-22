<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cambio propuesto por un monitor (asistencia, comportamiento o participación).
 * No toca los datos reales hasta que el docente lo aprueba — ver MonitorReviewService.
 */
class MonitorSubmission extends Model
{
    public const TYPES = ['attendance', 'behavior', 'participation'];

    protected $fillable = [
        'course_monitor_id', 'group_subject_id', 'submitted_by', 'type', 'payload',
        'status', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function courseMonitor(): BelongsTo
    {
        return $this->belongsTo(CourseMonitor::class);
    }

    public function groupSubject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

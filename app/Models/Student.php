<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id', 'first_name', 'last_name', 'document_type', 'document_number',
        'birthdate', 'gender', 'photo', 'address', 'phone', 'email', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function studentGroups(): HasMany
    {
        return $this->hasMany(StudentGroup::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(ParentGuardian::class, 'student_parents', 'student_id', 'parent_id')
            ->withPivot('is_primary');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function behaviorAnnotations(): HasMany
    {
        return $this->hasMany(BehaviorAnnotation::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(StudentObservation::class);
    }

    public function citations(): HasMany
    {
        return $this->hasMany(ParentCitation::class);
    }

    /**
     * True if $user currently teaches any active group-subject for a group this
     * student is actively enrolled in. Backs the "visible to every teacher who
     * teaches this student" rule for behavior annotations and citations.
     */
    public function isTaughtBy(User $user): bool
    {
        $activeGroupIds = $this->studentGroups()->where('status', 'activo')->pluck('group_id');

        if ($activeGroupIds->isEmpty()) {
            return false;
        }

        return GroupSubject::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('group_id', $activeGroupIds)
            ->exists();
    }

    /**
     * Admins of the institution and any teacher who teaches this student can
     * view/manage their behavior annotations and citations (not just whoever
     * registered the entry) — per the prompt's "observador del estudiante" rule.
     */
    public function canBeAccessedBy(User $user): bool
    {
        if (! $user->isActiveMemberOf($this->institution_id)) {
            return false;
        }

        return $user->isAdminOf($this->institution_id) || $this->isTaughtBy($user);
    }
}

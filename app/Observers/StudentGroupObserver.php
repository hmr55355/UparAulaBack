<?php

namespace App\Observers;

use App\Models\Group;
use App\Models\StudentGroup;

class StudentGroupObserver
{
    public function saved(StudentGroup $studentGroup): void
    {
        Group::find($studentGroup->group_id)?->refreshStudentCount();

        // Traslado de grupo: el grupo anterior también pierde un estudiante.
        if ($studentGroup->wasChanged('group_id')) {
            Group::find($studentGroup->getOriginal('group_id'))?->refreshStudentCount();
        }
    }

    public function deleted(StudentGroup $studentGroup): void
    {
        Group::find($studentGroup->group_id)?->refreshStudentCount();
    }
}

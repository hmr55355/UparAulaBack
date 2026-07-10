<?php

namespace App\Policies;

use App\Models\Institution;
use App\Models\User;

class InstitutionPolicy
{
    public function view(User $user, Institution $institution): bool
    {
        return $user->isActiveMemberOf($institution->id);
    }

    public function update(User $user, Institution $institution): bool
    {
        return $user->isAdminOf($institution->id);
    }

    public function manageTeachers(User $user, Institution $institution): bool
    {
        return $user->isAdminOf($institution->id);
    }

    public function manageAcademics(User $user, Institution $institution): bool
    {
        return $user->isAdminOf($institution->id);
    }
}

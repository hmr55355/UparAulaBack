<?php

namespace App\Policies;

use App\Models\GroupSubject;
use App\Models\User;

class GroupSubjectPolicy
{
    public function view(User $user, GroupSubject $groupSubject): bool
    {
        return $this->hasAccess($user, $groupSubject);
    }

    public function update(User $user, GroupSubject $groupSubject): bool
    {
        return $this->hasAccess($user, $groupSubject);
    }

    public function delete(User $user, GroupSubject $groupSubject): bool
    {
        return $user->isAdminOf($groupSubject->institution_id);
    }

    /**
     * A user has access to a group-subject if they are an active member of the
     * institution AND either own the group-subject or are an admin there.
     */
    private function hasAccess(User $user, GroupSubject $groupSubject): bool
    {
        if (! $user->isActiveMemberOf($groupSubject->institution_id)) {
            return false;
        }

        return $groupSubject->user_id === $user->id || $user->isAdminOf($groupSubject->institution_id);
    }
}

<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    /**
     * Importing overwrites attendance days that have already been recorded.
     */
    public function import(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    /**
     * Recording a day by hand, for staff the device missed.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->hasRole('Admin', 'HR');
    }
}

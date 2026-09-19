<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin', 'HR');
    }

    /**
     * Deleting staff records is destructive and irreversible, so it is limited
     * to Admin — HR maintains records but does not remove them.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Importing rewrites the staff records the workbook already covers, so it
     * is held to the same roles that may maintain them by hand.
     */
    public function import(User $user): bool
    {
        return $user->hasRole('Admin', 'HR');
    }
}

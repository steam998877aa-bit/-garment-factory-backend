<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * The audit trail records everyone's actions, so only Admin may read it.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->hasRole('Admin');
    }
}

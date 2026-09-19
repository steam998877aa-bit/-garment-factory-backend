<?php

namespace App\Policies;

use App\Models\ProductionTransfer;
use App\Models\User;

class ProductionTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    public function view(User $user, ProductionTransfer $transfer): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }
}

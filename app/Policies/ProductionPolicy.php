<?php

namespace App\Policies;

use App\Models\Production;
use App\Models\User;

class ProductionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    public function view(User $user, Production $production): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    public function update(User $user, Production $production): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    /**
     * Moving stock between departments changes the factory's physical record.
     */
    public function transfer(User $user, Production $production): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }

    /**
     * Importing rewrites every row the workbook already covers, so it is held
     * to the same roles that may create stock by hand.
     */
    public function import(User $user): bool
    {
        return $user->hasRole('Admin', 'Production Manager');
    }
}

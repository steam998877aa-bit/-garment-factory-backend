<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * The system roles the application ships with.
     *
     * @var list<array{name: string, description: string}>
     */
    protected array $roles = [
        [
            'name' => 'Admin',
            'description' => 'System Administrator',
        ],
        [
            'name' => 'HR',
            'description' => 'Human Resources & Employees Manager',
        ],
        [
            'name' => 'Production Manager',
            'description' => 'Factory & Transfers Supervisor',
        ],
        [
            'name' => 'Employee',
            'description' => 'Self-service access to own record only',
        ],
    ];

    /**
     * Seed the system roles.
     */
    public function run(): void
    {
        foreach ($this->roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']],
            );
        }
    }
}

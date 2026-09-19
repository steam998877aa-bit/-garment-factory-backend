<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the structural accounts the system needs to be usable and testable:
 * one account per role, so every access boundary can be exercised.
 *
 * Re-running is safe. Accounts are matched on email; name, username and role
 * are kept in sync, but an existing account's password is never overwritten so
 * a changed password survives a re-seed.
 */
class UserSeeder extends Seeder
{
    /**
     * The accounts to create, keyed to the roles from RoleSeeder.
     *
     * @var list<array{name: string, username: string, email: string, role: string}>
     */
    protected array $accounts = [
        [
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'admin@garment-factory.test',
            'role' => 'Admin',
        ],
        [
            'name' => 'HR Manager',
            'username' => 'hr',
            'email' => 'hr@garment-factory.test',
            'role' => 'HR',
        ],
        [
            'name' => 'Production Manager',
            'username' => 'production',
            'email' => 'production@garment-factory.test',
            'role' => 'Production Manager',
        ],
    ];

    /**
     * Seed the structural accounts.
     */
    public function run(): void
    {
        $password = config('garment_factory.seed_password');

        foreach ($this->accounts as $account) {
            $role = Role::where('name', $account['role'])->first();

            if ($role === null) {
                $this->command?->warn(
                    "Skipping {$account['username']}: role \"{$account['role']}\" does not exist. Run RoleSeeder first."
                );

                continue;
            }

            $user = User::firstOrNew(['email' => $account['email']]);

            $user->fill([
                'name' => $account['name'],
                'username' => $account['username'],
                'role_id' => $role->getKey(),
            ]);

            // Only set a password when the account is first created, so
            // re-seeding never resets a password someone has changed.
            if (! $user->exists) {
                $user->password = $password;
            }

            $existed = $user->exists;
            $user->save();

            $this->command?->line(sprintf(
                '  %s %s (%s) — %s',
                $existed ? 'synced ' : 'created',
                $account['username'],
                $account['email'],
                $role->name,
            ));
        }
    }
}

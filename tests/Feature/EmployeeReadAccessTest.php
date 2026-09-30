<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_role_can_read_the_directory_with_a_bearer_token(): void
    {
        (new RoleSeeder())->run();
        $employeeRole = Role::where('name', 'Employee')->firstOrFail();
        $user = User::factory()->create(['role_id' => $employeeRole->id]);
        $employee = Employee::create([
            'name' => 'Directory Test',
            'fingerprint_id' => 'DIR-001',
            'department' => 'Administration',
            'position' => 'Assistant',
            'phone' => '555-0100',
            'salary' => 0,
        ]);
        $token = $user->createToken('employee-directory-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/employees');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.name', 'Directory Test')
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.fingerprint_id');
    }

    public function test_employee_role_cannot_create_employee_records(): void
    {
        (new RoleSeeder())->run();
        $employeeRole = Role::where('name', 'Employee')->firstOrFail();
        $user = User::factory()->create(['role_id' => $employeeRole->id]);

        $this->actingAs($user)
            ->postJson('/api/employees', [
                'name' => 'Not Allowed',
                'fingerprint_id' => 'DIR-002',
                'department' => 'Administration',
            ])
            ->assertForbidden();
    }
}
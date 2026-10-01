<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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

    public function test_signed_document_routes_redirect_directly_to_cloudinary_without_a_bearer_token(): void
    {
        $idCardUrl = 'https://res.cloudinary.com/example/employee_documents/id-card.pdf';
        $cvUrl = 'https://res.cloudinary.com/example/employee_documents/cv.pdf';
        $employee = Employee::create([
            'name' => 'Document Redirect Test',
            'fingerprint_id' => 'DOC-001',
            'department' => 'Administration',
            'position' => 'Assistant',
            'phone' => '555-0101',
            'salary' => 0,
            'id_card_image' => $idCardUrl,
            'cv_file' => $cvUrl,
        ]);

        $idCardRoute = URL::temporarySignedRoute(
            'employees.id-card',
            now()->addMinutes(5),
            ['employee' => $employee->id],
        );
        $cvRoute = URL::temporarySignedRoute(
            'employees.cv',
            now()->addMinutes(5),
            ['employee' => $employee->id],
        );

        $this->get($idCardRoute)->assertRedirect($idCardUrl);
        $this->get($cvRoute)->assertRedirect($cvUrl);
    }

    public function test_invalid_employee_document_signature_returns_the_expected_404_json(): void
    {
        $employee = Employee::create([
            'name' => 'Invalid Signature Test',
            'fingerprint_id' => 'DOC-002',
            'department' => 'Administration',
            'position' => 'Assistant',
            'phone' => '555-0102',
            'salary' => 0,
        ]);

        $this->getJson("/api/employees/{$employee->id}/id-card")
            ->assertNotFound()
            ->assertExactJson([
                'url' => null,
                'message' => 'الملف غير موجود، يرجى إعادة الرفع',
            ]);
    }

    public function test_employee_document_with_non_url_path_returns_the_expected_404_json(): void
    {
        $employee = Employee::create([
            'name' => 'Legacy Document Test',
            'fingerprint_id' => 'DOC-003',
            'department' => 'Administration',
            'position' => 'Assistant',
            'phone' => '555-0103',
            'salary' => 0,
            'id_card_image' => 'employees/id_cards/legacy.pdf',
        ]);
        $signedUrl = URL::temporarySignedRoute(
            'employees.id-card',
            now()->addMinutes(5),
            ['employee' => $employee->id],
        );

        $this->getJson($signedUrl)
            ->assertNotFound()
            ->assertExactJson([
                'url' => null,
                'message' => 'الملف غير موجود، يرجى إعادة الرفع',
            ]);
    }

    public function test_portal_document_routes_redirect_cloudinary_and_return_404_for_local_paths(): void
    {
        $employee = Employee::create([
            'name' => 'Portal Document Test',
            'fingerprint_id' => 'DOC-004',
            'department' => 'Administration',
            'position' => 'Assistant',
            'phone' => '555-0104',
            'salary' => 0,
            'id_card_image' => 'employees/id_cards/legacy.pdf',
            'cv_file' => 'employees/cvs/legacy.pdf',
        ]);
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $token = $user->createToken('portal-document-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/portal/id-card')
            ->assertNotFound()
            ->assertExactJson([
                'url' => null,
                'message' => 'الملف غير موجود، يرجى إعادة الرفع',
            ]);
        $this->getJson('/api/portal/cv')
            ->assertNotFound()
            ->assertExactJson([
                'url' => null,
                'message' => 'الملف غير موجود، يرجى إعادة الرفع',
            ]);

        $idCardUrl = 'https://res.cloudinary.com/example/employee_documents/id-card.pdf';
        $cvUrl = 'https://res.cloudinary.com/example/employee_documents/cv.pdf';
        $employee->update([
            'id_card_image' => $idCardUrl,
            'cv_file' => $cvUrl,
        ]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/portal/id-card')->assertRedirect($idCardUrl);
        $this->getJson('/api/portal/cv')->assertRedirect($cvUrl);
    }
}
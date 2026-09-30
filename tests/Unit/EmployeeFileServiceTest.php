<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\EmployeeFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeFileServiceTest extends TestCase
{
    public function test_id_card_is_stored_on_the_public_local_disk(): void
    {
        Storage::fake('public');

        $employee = new Employee();
        $path = app(EmployeeFileService::class)->storeIdCard(
            $employee,
            UploadedFile::fake()->create('id-card.pdf', 20, 'application/pdf'),
        );

        $this->assertStringStartsWith('employees/id_cards/', $path);
        Storage::disk('public')->assertExists($path);
    }
}
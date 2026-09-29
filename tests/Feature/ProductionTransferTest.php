<?php

namespace Tests\Feature;

use App\Models\Production;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_transfer_to_composite_sewing_department_name(): void
    {
        (new RoleSeeder())->run();
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $adminRole->id,
            'password' => Hash::make('password123'),
        ]);

        $production = Production::create([
            'barcode' => 'TEST1234',
            'item_number' => 101,
            'model_name' => 'Test Model',
            'fabric' => 'ميلتون',
            'design_status' => 'موجود',
            'department' => 'جاهز قص',
            'quantity' => 50,
            'month' => 9,
        ]);

        $response = $this->actingAs($user)->postJson('/api/transfers', [
            'production_id' => $production->id,
            'to_department' => 'خياطة <مصطفى>',
            'quantity' => 50,
            'password' => 'password123',
        ]);

        $response->assertStatus(201);

        $production->refresh();
        $this->assertEquals('خياطة', $production->department);
        $this->assertEquals('مصطفى', $production->workshop);
    }
}

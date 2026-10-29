<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccountFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_type_and_activity_filters_apply_before_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin', 'active' => false]);
        User::factory()->count(26)->create(['role' => 'citizen']);
        User::factory()->create(['role' => 'citizen', 'active' => false]);
        foreach (Agency::all() as $agency) {
            foreach ([true, false] as $active) {
                User::factory()->create(['role' => 'officer', 'agency_id' => $agency->id, 'active' => $active]);
            }
        }
        User::factory()->create(['role' => 'officer', 'agency_id' => null]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/management/users?account_type=all')->assertOk()->assertJsonPath('meta.total', 36)->assertJsonCount(25, 'data');
        foreach (['officer' => 7, 'admin' => 2, 'citizen' => 27, 'fire' => 2, 'crime' => 2, 'mdrrmo' => 2] as $type => $total) {
            $this->getJson('/api/v1/management/users?account_type='.$type)->assertOk()->assertJsonPath('meta.total', $total);
        }
        foreach (['fire' => 'FIRE', 'crime' => 'PNP', 'mdrrmo' => 'MDRRMO'] as $type => $agency) {
            foreach (['active' => true, 'inactive' => false] as $activity => $active) {
                $this->getJson('/api/v1/management/users?account_type='.$type.'&activity='.$activity)->assertOk()
                    ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
                    ->assertJsonPath('data.0.role', 'officer')->assertJsonPath('data.0.agency.type', $agency)->assertJsonPath('data.0.active', $active);
            }
        }
        $this->getJson('/api/v1/management/users?account_type=admin&activity=inactive')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.active', false);
        $this->getJson('/api/v1/management/users?account_type=citizen&activity=inactive')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.role', 'citizen');
        $this->getJson('/api/v1/management/users?account_type=unrecognized')->assertUnprocessable();
        $this->getJson('/api/v1/management/users?activity=unrecognized')->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer']));
        $this->getJson('/api/v1/management/users?account_type=officer')->assertForbidden();
    }
}

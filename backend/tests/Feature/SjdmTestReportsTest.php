<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Incident;
use App\Models\User;
use App\Services\SjdmTestReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SjdmTestReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_five_per_agency_in_sjdm_without_notifications_or_duplicates(): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        $this->artisan('cdrmapp:seed-test-reports')->expectsOutputToContain('15 test reports created')->assertSuccessful();
        $reports = Incident::with('category')->get();
        $this->assertCount(15, $reports);
        $this->assertCount(15, $reports->map(fn ($report) => $report->latitude.','.$report->longitude)->unique());
        foreach (Agency::all() as $agency) {
            $this->assertSame(5, $reports->where('agency_id', $agency->id)->count());
        }
        foreach ($reports as $report) {
            $this->assertTrue($report->is_test);
            $this->assertSame($report->agency_id, $report->category->agency_id);
            $this->assertStringContainsString('[TEST REPORT', $report->description);
            $this->assertGreaterThanOrEqual(14.805, $report->latitude);
            $this->assertLessThanOrEqual(14.825, $report->latitude);
            $this->assertGreaterThanOrEqual(121.038, $report->longitude);
            $this->assertLessThanOrEqual(121.076, $report->longitude);
        }
        $this->assertFalse(User::where('email', SjdmTestReports::REPORTER_EMAIL)->firstOrFail()->active);
        $this->assertDatabaseCount('incident_status_histories', 15);
        $reports->first()->update(['workflow_status' => 'resolved']);
        $this->artisan('cdrmapp:seed-test-reports')->expectsOutputToContain('0 test reports created; 15 already existed')->assertSuccessful();
        $this->assertDatabaseCount('incidents', 15);
        $this->assertSame('resolved', $reports->first()->fresh()->getRawOriginal('workflow_status'));
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/analytics/heatmap?exploratory=1')->assertOk()->assertJsonCount(0, 'data.points');
        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(15, 'data');
        $this->get('/incident-map')->assertOk()->assertSee('Agency pin legend')->assertSee('images/map/fire.svg')->assertSee('images/map/police.svg')->assertSee('images/map/mdrrmo.svg');
    }

    public function test_reserved_reporter_account_is_never_overwritten(): void
    {
        $user = User::factory()->create(['email' => SjdmTestReports::REPORTER_EMAIL, 'name' => 'Existing account']);
        $this->artisan('cdrmapp:seed-test-reports')->expectsOutputToContain('already in use')->assertFailed();
        $this->assertSame('Existing account', $user->fresh()->name);
        $this->assertTrue($user->fresh()->active);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_missing_active_agency_leaves_no_partial_test_batch(): void
    {
        Agency::where('type', 'PNP')->update(['active' => false]);
        $this->artisan('cdrmapp:seed-test-reports')->expectsOutputToContain('active agency')->assertFailed();
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseMissing('users', ['email' => SjdmTestReports::REPORTER_EMAIL]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_and_staff_session_dashboard(): void
    {
        $this->get('/')->assertOk()->assertSee('Community preparedness');
        foreach (['services', 'announcements', 'team-members', 'contact-details', 'faqs'] as $type) {
            $this->get('/pages/'.$type)->assertOk();
        }
        $this->get('/dashboard')->assertRedirect('/login');
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('Response watchlist');
        $this->get('/manage/users')->assertOk();
    }
}

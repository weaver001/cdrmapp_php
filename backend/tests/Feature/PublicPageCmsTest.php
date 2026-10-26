<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\PublicPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicPageCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_every_page_header_and_paragraph_without_changing_items(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Synthetic page editor']));
        $content = Content::create(['type' => 'services', 'title' => 'Existing service', 'body' => 'Existing description', 'published' => true]);
        foreach (PublicPage::LABELS as $type => $label) {
            $data = ['eyebrow' => 'Community updates', 'heading' => 'Updated '.$label, 'introduction' => "First paragraph.\nSecond paragraph."];
            if ($type === 'contact-details') {
                $data += ['form_heading' => 'Write to our team', 'form_introduction' => 'Please use this form for general enquiries.'];
            }
            $this->getJson('/api/v1/management/pages/'.$type)->assertOk()->assertJsonPath('data.type', $type);
            $this->patchJson('/api/v1/management/pages/'.$type, $data)->assertOk()->assertJsonPath('data.heading', 'Updated '.$label);
            $this->get('/pages/'.$type)->assertOk()->assertSee('Updated '.$label)->assertSee('Second paragraph.');
            $this->getJson('/api/v1/public/pages/'.$type)->assertOk()->assertJsonPath('data.heading', 'Updated '.$label);
        }
        $this->get('/pages/contact-details')->assertSee('Write to our team')->assertSee('Please use this form for general enquiries.');
        $this->get('/')->assertSee('Updated Announcements');
        $this->assertSame('Existing service', $content->fresh()->title);
        $this->getJson('/api/v1/public/services')->assertJsonPath('data.0.title', 'Existing service');
        $this->assertDatabaseCount('public_pages', 5);
        $audit = AuditLog::where('action', 'page.updated')->firstOrFail();
        $this->assertSame('Synthetic page editor', $audit->actor_name);
        $this->assertSame('Services page', $audit->record_label);
        $this->assertSame('Services', $audit->metadata['before']['heading']);
        $this->assertSame('Updated Services', $audit->metadata['after']['heading']);
    }

    public function test_page_management_is_admin_only_and_unknown_pages_are_unavailable(): void
    {
        $this->getJson('/api/v1/management/pages/services')->assertUnauthorized();
        $this->patchJson('/api/v1/management/pages/services', ['heading' => 'Attempt'])->assertUnauthorized();
        $this->get('/manage/cms')->assertRedirect('/login');
        foreach (['citizen', 'officer'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/management/pages/services')->assertForbidden();
            $this->patchJson('/api/v1/management/pages/services', ['heading' => 'Attempt'])->assertForbidden();
            $this->get('/manage/cms')->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/management/pages/infographics')->assertNotFound();
        $this->getJson('/api/v1/public/pages/infographics')->assertNotFound();
    }

    public function test_cms_is_one_parent_module_with_five_page_editors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get('/manage/cms')->assertOk()->assertSee('Content management');
        $this->assertSame(1, substr_count($response->getContent(), 'class="cms-nav"'));
        foreach (PublicPage::LABELS as $type => $label) {
            $response->assertSee('Manage '.$label);
            $this->get('/manage/'.$type)->assertOk()->assertSee('id="page-editor"', false)
                ->assertSee('name="heading"', false)->assertSee('name="introduction"', false)
                ->assertSee('id="editor"', false)->assertSee('Clear Field');
        }
        $response->assertDontSee('/manage/dispatch-rules')->assertDontSee('/manage/infographics');
    }

    public function test_headers_render_as_safe_text_and_types_cannot_be_reassigned(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $heading = '<script>alert("synthetic")</script>';
        $this->patchJson('/api/v1/management/pages/services', ['heading' => $heading, 'type' => 'infographics', 'introduction' => '<img src=x onerror=alert(1)>'])->assertOk()->assertJsonPath('data.type', 'services');
        $this->get('/pages/services')->assertOk()->assertSee($heading)->assertDontSee($heading, false)
            ->assertDontSee('<img src=x', false);
        $this->assertDatabaseMissing('public_pages', ['type' => 'infographics']);
    }

    public function test_invalid_page_settings_preserve_saved_values(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/v1/management/pages/services', ['heading' => ''])->assertUnprocessable();
        $this->patchJson('/api/v1/management/pages/services', ['heading' => 'Services', 'introduction' => str_repeat('x', 3001)])->assertUnprocessable();
        $this->patchJson('/api/v1/management/pages/contact-details', ['heading' => 'Contact', 'form_heading' => ''])->assertUnprocessable();
        $this->assertSame('Services', PublicPage::where('type', 'services')->firstOrFail()->heading);
    }

    public function test_native_page_form_saves_and_returns_to_its_cms_submodule(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patch('/manage/cms/faqs', ['heading' => 'Questions and answers', 'introduction' => 'Updated FAQ introduction.'])
            ->assertRedirect('/manage/faqs')->assertSessionHas('status', 'Page updated successfully.');
        $this->get('/pages/faqs')->assertOk()->assertSee('Questions and answers')->assertSee('Updated FAQ introduction.');
    }
}

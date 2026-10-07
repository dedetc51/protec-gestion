<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdministrationAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_pages_have_one_main_heading_and_labeled_visible_controls(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $department = Department::factory()->create();

        foreach (['/admin', '/admin/organization', '/admin/assignments', '/admin/assignments/'.$subject->id.'/edit', '/admin/permissions', '/admin/permissions/departments/'.$department->id] as $url) {
            $xpath = $this->document($this->actingAs($actor)->get($url)->assertOk()->getContent());
            $this->assertSame(1, $xpath->query('//main//h1')->length, $url);
            foreach ($xpath->query('//main//input[not(@type="hidden")]|//main//select') as $control) {
                $this->assertNotSame('', $control->getAttribute('id'), $url);
                $this->assertGreaterThan(0, $xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length, $url);
            }
            foreach ($xpath->query('//main//fieldset') as $fieldset) {
                $this->assertSame('legend', $fieldset->firstElementChild->tagName, $url);
            }
        }
    }

    public function test_permission_matrix_has_caption_and_text_with_state_icons(): void
    {
        $department = Department::factory()->create();

        $response = $this->actingAs($this->admin())->get('/admin/permissions/departments/'.$department->id);
        $response->assertSee('Héritée')->assertSee('Accordée')->assertSee('Refusée');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//table/caption')->length);
        $this->assertGreaterThan(0, $xpath->query('//select/option[contains(text(), "✓ Accordée")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//select/option[contains(text(), "− Refusée")]')->length);
    }

    public function test_success_feedback_is_live_on_shared_administration_layout(): void
    {
        $response = $this->actingAs($this->admin())->withSession(['status' => 'Affectations enregistrées.'])->get('/admin');
        $xpath = $this->document($response->getContent());

        $this->assertSame(1, $xpath->query('//main//*[@role="status" and @aria-live="polite" and contains(., "Affectations enregistrées.")]')->length);
    }

    public function test_organization_validation_summary_links_to_a_visible_form_control(): void
    {
        $this->actingAs($this->admin())->from('/admin/organization')->post('/admin/organization/departments', ['name' => ''])->assertSessionHasErrors('name');
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        $response = $this->get('/admin/organization')->assertSee('Le nom est obligatoire.');
        $xpath = $this->document($response->getContent());
        $links = $xpath->query('//main//*[@role="alert"]//a');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $target = substr($link->getAttribute('href'), 1);
            $this->assertSame(1, $xpath->query('//*[@id="'.$target.'" and not(@type="hidden")]')->length);
        }
    }

    public function test_assignment_validation_summary_has_existing_focusable_targets(): void
    {
        $subject = User::factory()->create();
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['assignments.0.scope_type' => 'Périmètre invalide.', 'represented' => 'Rechargez le formulaire.']));
        $this->actingAs($this->admin())->withSession(['errors' => $errors]);
        $this->app['session']->save();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        $xpath = $this->document($this->get('/admin/assignments/'.$subject->id.'/edit')->getContent());
        $links = $xpath->query('//main//*[@role="alert"]//a');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $target = substr($link->getAttribute('href'), 1);
            $this->assertSame(1, $xpath->query('//*[@id="'.$target.'" and not(@type="hidden")]')->length);
        }
    }

    public function test_president_branch_validation_summary_targets_an_editable_control(): void
    {
        $department = Department::factory()->create();
        $actor = User::factory()->create();
        RoleAssignment::factory()->for($actor)->create(['role_id' => Role::where('slug', 'department-president')->value('id'), 'scope_type' => 'department', 'scope_id' => $department->id]);
        $this->actingAs($actor)->from('/admin/organization')->post('/admin/organization/branches', ['department_id' => $department->id, 'name' => ''])->assertSessionHasErrors('name');
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        $xpath = $this->document($this->get('/admin/organization')->getContent());
        $links = $xpath->query('//main//*[@role="alert"]//a');

        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $target = substr($link->getAttribute('href'), 1);
            $this->assertSame(1, $xpath->query('//input[@id="'.$target.'" and not(@type="hidden")]')->length);
        }
    }

    public function test_matrix_validation_summary_falls_back_for_unrendered_cells(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['cells.99999.99999' => 'Permission inconnue.']));
        $this->actingAs($this->admin())->withSession(['errors' => $errors]);
        $this->app['session']->save();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        $xpath = $this->document($this->get('/admin/permissions')->assertSee('Permission inconnue.')->getContent());

        $this->assertSame(1, $xpath->query('//main//*[@role="alert"]//a[@href="#matrix-form"]')->length);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->create(['role_id' => Role::where('slug', 'technical-admin')->value('id')]);

        return $user;
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\SecurityAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_events_are_audited_without_secrets(): void
    {
        $user = User::factory()->create(['password' => 'Secret-password-42']);
        $this->withHeader('User-Agent', str_repeat('browser', 100))->post('/login', [
            'email' => $user->email, 'password' => 'Secret-password-42', 'password_confirmation' => 'leak',
        ]);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

        $this->assertSame(['auth.login.succeeded', 'auth.logout', 'auth.login.failed'], AuditEvent::pluck('event')->all());
        $serialized = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('Secret-password-42', $serialized);
        $this->assertStringNotContainsString('leak', $serialized);
        $this->assertLessThanOrEqual(255, AuditEvent::first()->user_agent_summary ? strlen(AuditEvent::first()->user_agent_summary) : 0);
    }

    public function test_metadata_uses_a_recursive_allowlist_and_events_are_immutable(): void
    {
        $request = request()->merge(['current_password' => 'request-secret']);
        $event = app(SecurityAudit::class)->record('test', 'success', null, $request, [
            'route' => 'dashboard', 'password' => 'secret', 'access_token' => 'token',
            'nested' => ['password' => 'nested-secret'], 'cookie' => 'cookie-secret',
        ]);
        $this->assertSame(['route' => 'dashboard'], $event->metadata);
        $this->expectException(\LogicException::class);
        $event->update(['outcome' => 'changed']);
    }
}

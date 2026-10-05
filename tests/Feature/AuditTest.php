<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
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
}

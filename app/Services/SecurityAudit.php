<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityAudit
{
    private const SENSITIVE = ['password', 'password_confirmation', 'cookie', 'authorization', 'token', 'secret'];

    public function record(string $event, string $outcome, ?User $actor, Request $request, array $metadata = []): AuditEvent
    {
        $safe = collect($metadata)->reject(fn ($value, $key) => in_array(strtolower((string) $key), self::SENSITIVE, true))->all();

        return AuditEvent::create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent_summary' => mb_substr((string) $request->userAgent(), 0, 255),
            'metadata' => $safe,
        ]);
    }
}

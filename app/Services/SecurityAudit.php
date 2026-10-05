<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityAudit
{
    private const ALLOWED_METADATA = ['action', 'email_hash', 'route', 'target_id'];

    public function record(string $event, string $outcome, ?User $actor, Request $request, array $metadata = []): AuditEvent
    {
        $safe = collect($metadata)->only(self::ALLOWED_METADATA)->map(fn ($value) => is_scalar($value) || $value === null ? $value : null)->filter(fn ($value) => $value !== null)->all();

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

<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class EnsureInitialAdmin extends Command
{
    protected $signature = 'protec:ensure-initial-admin {--name=} {--email=}';

    protected $description = 'Create the single initial Protec-Gestion administrator';

    public function handle(): int
    {
        $name = $this->option('name') ?: env('INITIAL_ADMIN_NAME');
        $email = strtolower(trim((string) ($this->option('email') ?: env('INITIAL_ADMIN_EMAIL'))));
        $password = env('INITIAL_ADMIN_PASSWORD');
        if (Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->fails()) {
            $this->error('Configuration administrateur invalide.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($name, $email, $password): int {
            $technicalRole = Role::where('slug', 'technical-admin')->lockForUpdate()->firstOrFail();
            $existing = User::whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->role === 'admin' && $existing->deactivated_at === null && User::where('role', 'admin')->count() === 1) {
                    $this->ensureTechnicalAssignment($existing, $technicalRole);
                    $this->info('Administrateur déjà configuré.');

                    return self::SUCCESS;
                }
                $this->error('Un compte incompatible existe déjà.');

                return self::FAILURE;
            }
            if (User::where('role', 'admin')->exists() || User::exists()) {
                $this->error('La création d’un second administrateur est refusée.');

                return self::FAILURE;
            }
            $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'must_change_password' => true]);
            $user->forceFill(['role' => 'admin'])->save();
            $this->ensureTechnicalAssignment($user, $technicalRole);
            $this->info('Administrateur initial créé.');

            return self::SUCCESS;
        });
    }

    private function ensureTechnicalAssignment(User $user, Role $role): void
    {
        $assignments = $user->roleAssignments()->where('role_id', $role->id)->where('scope_type', 'global')->whereNull('scope_id');
        $at = now();
        if ((clone $assignments)->active($at)->exists()) {
            return;
        }
        $nextStart = (clone $assignments)->where('starts_at', '>', $at)
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhereColumn('ends_at', '>', 'starts_at'))
            ->orderBy('starts_at')->first()?->starts_at;
        $assignment = $user->roleAssignments()->create(['role_id' => $role->id, 'scope_type' => 'global', 'scope_id' => null, 'starts_at' => $at, 'ends_at' => $nextStart]);
        AuditEvent::create(['actor_id' => null, 'event' => 'members.role.assigned', 'outcome' => 'success', 'metadata' => [
            'action' => 'bootstrap', 'target_id' => $user->id, 'record_id' => $assignment->id,
            'scope_type' => 'global', 'scope_id' => null, 'before' => [], 'after' => $assignment->only(['role_id', 'scope_type', 'scope_id', 'starts_at', 'ends_at']),
        ]]);
    }
}

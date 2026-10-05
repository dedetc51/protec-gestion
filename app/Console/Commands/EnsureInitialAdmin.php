<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class EnsureInitialAdmin extends Command
{
    protected $signature = 'protec:ensure-initial-admin {--name=} {--email=} {--password=}';

    protected $description = 'Create the single initial Protec-Gestion administrator';

    public function handle(): int
    {
        $name = $this->option('name') ?: env('INITIAL_ADMIN_NAME');
        $email = strtolower(trim((string) ($this->option('email') ?: env('INITIAL_ADMIN_EMAIL'))));
        $password = $this->option('password') ?: env('INITIAL_ADMIN_PASSWORD');
        if (Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->fails()) {
            $this->error('Configuration administrateur invalide.');

            return self::FAILURE;
        }

        $existing = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($existing) {
            if ($existing->role === 'admin' && User::where('role', 'admin')->count() === 1) {
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
        User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'must_change_password' => true])
            ->forceFill(['role' => 'admin'])->save();
        $this->info('Administrateur initial créé.');

        return self::SUCCESS;
    }
}

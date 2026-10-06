<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class PwnedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hash = strtoupper(sha1((string) $value));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::accept('text/plain')->withHeaders(['Add-Padding' => 'true'])
                ->withUserAgent('Protec-Gestion password safety check')->timeout(3)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (\Throwable) {
            $fail('La sécurité du mot de passe ne peut pas être vérifiée pour le moment. Réessayez plus tard.');

            return;
        }

        if (! $response->successful()) {
            $fail('La sécurité du mot de passe ne peut pas être vérifiée pour le moment. Réessayez plus tard.');

            return;
        }

        $body = trim($response->body());
        $lines = $body === '' ? [] : preg_split('/\r?\n/', $body);
        if (! $lines) {
            $fail('La sécurité du mot de passe ne peut pas être vérifiée pour le moment. Réessayez plus tard.');

            return;
        }

        foreach ($lines as $line) {
            if (preg_match('/^([A-F0-9]{35}):\d+$/', $line, $matches) !== 1) {
                $fail('La sécurité du mot de passe ne peut pas être vérifiée pour le moment. Réessayez plus tard.');

                return;
            }

            if (hash_equals($suffix, $matches[1])) {
                $fail('Ce mot de passe apparaît dans une fuite de données. Choisissez-en un autre.');

                return;
            }
        }
    }
}

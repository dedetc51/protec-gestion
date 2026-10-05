<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OfflinePasswordSafety implements ValidationRule
{
    private const DENYLIST = ['password1234!', 'azerty123456!', 'admin123456!', 'motdepasse123!'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array(mb_strtolower((string) $value), self::DENYLIST, true)) {
            $fail('Ce mot de passe est trop répandu. Choisissez-en un autre.');
        }
    }
}

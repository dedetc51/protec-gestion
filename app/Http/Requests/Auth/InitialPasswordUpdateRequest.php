<?php

namespace App\Http\Requests\Auth;

use App\Rules\OfflinePasswordSafety;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class InitialPasswordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols(), new OfflinePasswordSafety],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Le mot de passe temporaire est incorrect.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.different' => 'Le nouveau mot de passe doit être différent du mot de passe temporaire.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins :min caractères.',
            'password.letters' => 'Le nouveau mot de passe doit contenir des lettres.',
            'password.mixed' => 'Le nouveau mot de passe doit contenir des majuscules et des minuscules.',
            'password.numbers' => 'Le nouveau mot de passe doit contenir au moins un chiffre.',
            'password.symbols' => 'Le nouveau mot de passe doit contenir au moins un symbole.',
        ];
    }
}

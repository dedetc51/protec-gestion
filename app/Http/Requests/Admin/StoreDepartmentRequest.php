<?php

namespace App\Http\Requests\Admin;

use App\Authorization\AuthorizationContext;
use App\Models\Department;
use App\Support\OrganizationName;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canIn('departments.manage', AuthorizationContext::global()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => OrganizationName::display($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
            $query = Department::query()->when($this->route('department'), fn ($query) => $query->whereKeyNot($this->route('department')->id));
            $key = OrganizationName::key($value);
            if ((clone $query)->where('name_key', $key)->exists()
                || (clone $query)->whereNull('name_key')->pluck('name')->contains(fn (string $name): bool => OrganizationName::key($name) === $key)) {
                $fail('Ce nom de département existe déjà.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['name.required' => 'Le nom est obligatoire.', 'name.string' => 'Le nom doit être un texte.', 'name.max' => 'Le nom ne doit pas dépasser 255 caractères.'];
    }
}

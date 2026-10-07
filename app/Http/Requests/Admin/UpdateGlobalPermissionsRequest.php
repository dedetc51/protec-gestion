<?php

namespace App\Http\Requests\Admin;

use App\Services\PermissionMatrixService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGlobalPermissionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(PermissionMatrixService $service): bool
    {
        return $this->user() !== null && $service->canManageGlobal($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return PermissionMatrixService::rules(false);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('cells')) {
            $this->merge(['cells' => []]);
        }
    }

    public function messages(): array
    {
        return PermissionMatrixService::messages();
    }
}

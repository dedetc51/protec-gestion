<?php

namespace App\Http\Requests\Admin;

use App\Services\PermissionMatrixService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentPermissionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(PermissionMatrixService $service): bool
    {
        return $this->user() !== null && $service->canManageDepartment($this->user(), $this->route('department'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return PermissionMatrixService::rules(true);
    }

    public function messages(): array
    {
        return PermissionMatrixService::messages();
    }
}

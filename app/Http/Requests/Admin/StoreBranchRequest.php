<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\Department;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $branch = $this->route('branch');
        $departmentId = filter_var($this->input('department_id'), FILTER_VALIDATE_INT);
        $department = $departmentId === false ? null : Department::find($departmentId);
        if ($actor === null || $department === null) {
            return false;
        }

        return ($branch === null || Gate::forUser($actor)->allows('update', $branch))
            && Gate::forUser($actor)->allows('create', [Branch::class, $department]);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deactivated_at')],
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (Branch::where('department_id', $this->input('department_id'))->whereRaw('lower(name) = ?', [mb_strtolower($value)])->when($this->route('branch'), fn ($query) => $query->whereKeyNot($this->route('branch')->id))->exists()) {
                    $fail('Ce nom d’antenne existe déjà dans ce département.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Le nom est obligatoire.', 'name.string' => 'Le nom doit être un texte.', 'name.max' => 'Le nom ne doit pas dépasser 255 caractères.', 'department_id.exists' => 'Choisissez un département actif.'];
    }
}

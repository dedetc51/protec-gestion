<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\Department;
use App\Support\OrganizationName;
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
            $this->merge(['name' => OrganizationName::display($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deactivated_at')],
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $query = Branch::where('department_id', $this->input('department_id'))->when($this->route('branch'), fn ($query) => $query->whereKeyNot($this->route('branch')->id));
                $key = OrganizationName::key($value);
                if ((clone $query)->where('name_key', $key)->exists()
                    || (clone $query)->whereNull('name_key')->pluck('name')->contains(fn (string $name): bool => OrganizationName::key($name) === $key)) {
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

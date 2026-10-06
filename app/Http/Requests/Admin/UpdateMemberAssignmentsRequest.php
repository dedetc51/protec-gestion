<?php

namespace App\Http\Requests\Admin;

use App\Services\MemberAssignmentService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberAssignmentsRequest extends FormRequest
{
    public function authorize(MemberAssignmentService $service): bool
    {
        return $this->user() !== null && $service->canManage($this->route('user'), $this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('selection_mode') !== '1') {
            return;
        }
        foreach (['memberships', 'assignments'] as $key) {
            $rows = $this->input($key, []);
            if (! is_array($rows)) {
                continue;
            }
            $selected = [];
            foreach ($rows as $index => $row) {
                if (is_array($row) && ($row['enabled'] ?? null) === '1') {
                    unset($row['enabled']);
                    $selected[$index] = $row;
                }
            }
            $this->merge([$key => $selected]);
        }
    }

    public function rules(): array
    {
        return MemberAssignmentService::rules() + ['submission_complete' => ['required_if:selection_mode,1', 'in:1']];
    }

    public function messages(): array
    {
        return [
            'present' => 'La liste des affectations doit être fournie.',
            'date' => 'Saisissez une date valide.',
            'after' => 'La date de fin doit être postérieure à la date de début.',
            'distinct' => 'Cette antenne est déjà sélectionnée.',
            'exists' => 'La sélection est invalide.',
            'required' => 'Ce champ est obligatoire.',
            'integer' => 'La sélection est invalide.',
            'array' => 'La liste des affectations est invalide.',
            'max' => 'La liste des affectations dépasse la taille autorisée.',
            'in' => 'La sélection est invalide.',
            'submission_complete.required_if' => 'Le formulaire est incomplet. Rechargez la page avant de réessayer.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Services\MemberAssignmentService;
use App\Support\MemberAssignmentForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberAssignmentsRequest extends FormRequest
{
    private bool $completeEditor = false;

    private array $context = [];

    public function authorize(MemberAssignmentService $service): bool
    {
        return $this->user() !== null && $service->canManage($this->route('user'), $this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('selection_mode') !== '1') {
            return;
        }
        $form = app(MemberAssignmentForm::class);
        $raw = $this->all();
        $manifest = $form->decode($this->input('editor_token'));
        if ($this->user() !== null && $manifest !== null && ($manifest['actor_id'] ?? null) === $this->user()->id
            && ($manifest['subject_id'] ?? null) === $this->route('user')->id) {
            $editor = $form->editor($this->route('user'), $this->user(), $manifest['scope'], $manifest['page']);
            $this->completeEditor = $form->matches($raw, $editor['manifest']);
            $this->context = ['scope' => $editor['scope'], 'page' => $editor['manifest']['page']];
            $this->merge(['represented' => $this->completeEditor ? $form->represented($editor['manifest']) : []]);
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
        return MemberAssignmentService::rules() + [
            'selection_mode' => ['sometimes', 'in:1'],
            'submission_complete' => ['required_if:selection_mode,1', 'in:1'],
            'editor_token' => ['required_if:selection_mode,1', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->exists('selection_mode') && ! $this->completeEditor) {
                $validator->errors()->add('editor_token', 'Le formulaire est incomplet ou a changé. Rechargez la page avant de réessayer.');
            }
        }];
    }

    public function editorContext(): array
    {
        return $this->context;
    }

    public function editorToken(): ?string
    {
        return $this->completeEditor ? $this->input('editor_token') : null;
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

@extends('layouts.app')
@section('title', 'Affectations de '.$user->name)
@section('content')
<div class="page-heading"><h1>Affectations de {{ $user->name }}</h1>
<p>{{ $user->email }}</p>
</div>
<p>Cochez les appartenances et les responsabilités à conserver. Les cases décochées terminent uniquement les périodes affichées dans ce formulaire. Les autres périodes et départements conservent leurs affectations.</p>
<p>Les dates sont facultatives. Une fin exclut l’accès dès cet instant. Modifier une période en cours crée une nouvelle période à partir d’aujourd’hui et conserve la précédente.</p>
@if($errors->any())
<div class="alert" role="alert" tabindex="-1" data-error-summary><h2>Vérifiez les affectations</h2><ul>
@foreach($errors->messages() as $field => $messages)
@php
    $target = 'assignment-form';
    if (preg_match('/^(memberships|assignments)\.([0-9]+)(?:\.(branch_id|role_id|scope_type|scope_id|starts_at|ends_at))?$/', $field, $matches)) {
        $rows = $matches[1] === 'memberships' ? $membershipRows : $assignmentRows;
        if (isset($rows[(int) $matches[2]])) {
            $suffix = $matches[3] ?? null;
            $target = $matches[1].'_'.$matches[2];
            if ($matches[1] === 'assignments' && in_array($suffix, [null, 'scope_id', 'scope_type'], true)) {
                $target .= '_scope_id';
            } elseif ($suffix) {
                $target .= '_'.$suffix;
            }
        }
    } elseif (in_array($field, ['memberships', 'assignments'], true)) {
        $target = $field;
    }
@endphp
<li><a href="#{{ $target }}">{{ $messages[0] }}</a></li>
@endforeach
</ul></div>
@endif
<form id="assignment-form" tabindex="-1" method="post" action="{{ route('admin.assignments.update', $user) }}">
@csrf @method('PUT')
<input type="hidden" name="selection_mode" value="1">
<fieldset id="memberships" tabindex="-1"><legend>Appartenances aux antennes</legend>
@foreach($membershipRows as $index => $row)
<fieldset id="memberships_{{ $index }}" tabindex="-1"><legend>{{ $row['label'] }}</legend>
<input type="hidden" name="memberships[{{ $index }}][branch_id]" value="{{ $row['branch_id'] }}">
@if($row['record_id'] !== null)<input type="hidden" name="represented[membership_ids][]" value="{{ $row['record_id'] }}">@endif
<label for="memberships_{{ $index }}_branch_id"><input id="memberships_{{ $index }}_branch_id" type="checkbox" name="memberships[{{ $index }}][enabled]" value="1" @checked(old('selection_mode') ? old("memberships.$index.branch_id") !== null : $row['enabled'])>Membre de cette antenne</label>
<label for="memberships_{{ $index }}_starts_at">Date de début</label><input id="memberships_{{ $index }}_starts_at" type="datetime-local" step="1" name="memberships[{{ $index }}][starts_at]" value="{{ old("memberships.$index.starts_at", $row['starts_at']) }}">
<label for="memberships_{{ $index }}_ends_at">Date de fin</label><input id="memberships_{{ $index }}_ends_at" type="datetime-local" step="1" name="memberships[{{ $index }}][ends_at]" value="{{ old("memberships.$index.ends_at", $row['ends_at']) }}">
</fieldset>
@endforeach
</fieldset>
<fieldset id="assignments" tabindex="-1"><legend>Responsabilités départementales, d’antenne et globales</legend>
@foreach(['department' => 'Rôles départementaux', 'branch' => 'Rôles d’antenne', 'global' => 'Rôles techniques globaux'] as $scope => $label)
@if(collect($assignmentRows)->contains('scope_type', $scope))
<fieldset><legend>{{ $label }}</legend>
@foreach($assignmentRows as $index => $row)
@if($row['scope_type'] === $scope)
<fieldset id="assignments_{{ $index }}_scope_id" tabindex="-1"><legend>{{ $row['label'] }}</legend>
<input type="hidden" name="assignments[{{ $index }}][role_id]" value="{{ $row['role_id'] }}"><input type="hidden" name="assignments[{{ $index }}][scope_type]" value="{{ $row['scope_type'] }}"><input type="hidden" name="assignments[{{ $index }}][scope_id]" value="{{ $row['scope_id'] }}">
@if($row['record_id'] !== null)<input type="hidden" name="represented[assignment_ids][]" value="{{ $row['record_id'] }}">@endif
<label for="assignments_{{ $index }}_role_id"><input id="assignments_{{ $index }}_role_id" type="checkbox" name="assignments[{{ $index }}][enabled]" value="1" @checked(old('selection_mode') ? old("assignments.$index.role_id") !== null : $row['enabled'])>Attribuer cette responsabilité</label>
<label for="assignments_{{ $index }}_starts_at">Date de début</label><input id="assignments_{{ $index }}_starts_at" type="datetime-local" step="1" name="assignments[{{ $index }}][starts_at]" value="{{ old("assignments.$index.starts_at", $row['starts_at']) }}">
<label for="assignments_{{ $index }}_ends_at">Date de fin</label><input id="assignments_{{ $index }}_ends_at" type="datetime-local" step="1" name="assignments[{{ $index }}][ends_at]" value="{{ old("assignments.$index.ends_at", $row['ends_at']) }}">
</fieldset>
@endif
@endforeach
</fieldset>
@endif
@endforeach
</fieldset>
<input type="hidden" name="submission_complete" value="1">
<button id="submission_complete" type="submit">Enregistrer les affectations</button>
</form>
<details><summary>Historique du périmètre autorisé</summary>
<ul>
@foreach($memberships as $membership)<li>Appartenance #{{ $membership->id }} — Antenne #{{ $membership->branch_id }} — {{ $membership->starts_at?->format('d/m/Y H:i') ?? 'Début non précisé' }} → {{ $membership->ends_at?->format('d/m/Y H:i') ?? 'Sans fin' }}</li>@endforeach
@foreach($assignments as $assignment)<li>{{ $assignment->role->name }} — {{ $assignment->scope_type === 'global' ? 'Global' : ($assignment->scope_type === 'department' ? 'Département' : 'Antenne') }} #{{ $assignment->scope_id }} — {{ $assignment->starts_at?->format('d/m/Y H:i') ?? 'Début non précisé' }} → {{ $assignment->ends_at?->format('d/m/Y H:i') ?? 'Sans fin' }}</li>@endforeach
</ul>
</details>
<a href="{{ route('admin.assignments.index') }}">Revenir aux membres</a>
@endsection

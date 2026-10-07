@extends('layouts.app')
@section('title', 'Organisation')
@section('content')
<div class="page-heading"><h1>Départements et antennes</h1>
<p>Gérez les antennes de votre périmètre. La désactivation conserve les données et l’historique.</p>
</div>
@if($errors->any())
@php
    $errorTargets = $technical ? ['department-name'] : [];
    foreach ($departments as $visibleDepartment) {
        if (!$visibleDepartment->deactivated_at) {
            if ($technical) {
                $errorTargets[] = 'department-name-'.$visibleDepartment->id;
            }
            $errorTargets[] = 'new-branch-'.$visibleDepartment->id;
            foreach ($visibleDepartment->branches as $visibleBranch) {
                if (!$visibleBranch->deactivated_at) {
                    $errorTargets[] = 'branch-name-'.$visibleBranch->id;
                }
            }
        }
    }
    $errorTarget = in_array(old('error_target'), $errorTargets, true) ? old('error_target') : ($errorTargets[0] ?? 'organization-form');
@endphp
<div class="alert" role="alert" tabindex="-1" data-error-summary><h2>Vérifiez les champs du formulaire</h2><ul>@foreach($errors->all() as $error)<li><a href="#{{ $errorTarget }}">{{ $error }}</a></li>@endforeach</ul></div>
@endif
<div id="organization-form" tabindex="-1">
@if($technical)
<section class="panel" aria-labelledby="new-department">
<h2 id="new-department">Créer un département</h2>
<form method="post" action="{{ route('admin.organization.departments.store') }}">@csrf
<input type="hidden" name="error_target" value="department-name">
<label for="department-name">Nom du département</label>
<input id="department-name" name="name" value="{{ old('name') }}" required maxlength="255">
<button type="submit">Créer le département</button>
</form>
</section>
@endif
@foreach($departments as $department)
<section class="panel" aria-labelledby="department-{{ $department->id }}">
<h2 id="department-{{ $department->id }}">{{ $department->name }} — {{ $department->deactivated_at ? 'Désactivé' : 'Actif' }}</h2>
@if($technical && !$department->deactivated_at)
<form method="post" action="{{ route('admin.organization.departments.update', $department) }}">@csrf @method('PATCH')
<input type="hidden" name="error_target" value="department-name-{{ $department->id }}">
<label for="department-name-{{ $department->id }}">Nom du département</label><input id="department-name-{{ $department->id }}" name="name" value="{{ $department->name }}" required maxlength="255"><button>Enregistrer le département</button>
</form>
<form method="post" action="{{ route('admin.organization.departments.destroy', $department) }}">@csrf @method('DELETE')<button>Désactiver le département {{ $department->name }}</button></form>
@endif
<ul>
@forelse($department->branches as $branch)
<li>
<h3>{{ $branch->name }} — {{ $branch->deactivated_at ? 'Désactivée' : 'Active' }}</h3>
@if(!$branch->deactivated_at && !$department->deactivated_at)
<form method="post" action="{{ route('admin.organization.branches.update', $branch) }}">@csrf @method('PATCH')
<input type="hidden" name="error_target" value="branch-name-{{ $branch->id }}">
<input type="hidden" name="department_id" value="{{ $department->id }}">
<label for="branch-name-{{ $branch->id }}">Nom de l’antenne</label><input id="branch-name-{{ $branch->id }}" name="name" value="{{ $branch->name }}" required maxlength="255"><button>Enregistrer l’antenne</button>
</form>
<form method="post" action="{{ route('admin.organization.branches.destroy', $branch) }}">@csrf @method('DELETE')<button>Désactiver l’antenne {{ $branch->name }}</button></form>
@endif
</li>
@empty<li>Aucune antenne.</li>@endforelse
</ul>
@if(!$department->deactivated_at)
<form method="post" action="{{ route('admin.organization.branches.store') }}">@csrf
<input type="hidden" name="error_target" value="new-branch-{{ $department->id }}">
<fieldset><legend>Ajouter une antenne à {{ $department->name }}</legend>
<input type="hidden" name="department_id" value="{{ $department->id }}">
<label for="new-branch-{{ $department->id }}">Nom de la nouvelle antenne</label><input id="new-branch-{{ $department->id }}" name="name" required maxlength="255"><button>Créer l’antenne</button>
</fieldset>
</form>
@endif
</section>
@endforeach
</div>
@endsection

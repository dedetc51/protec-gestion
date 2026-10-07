@extends('layouts.app')
@section('title', 'Affectations des membres')
@section('content')
<div class="page-heading"><h1>Affectations des membres</h1><p>Recherchez un membre pour gérer ses antennes et ses responsabilités.</p></div>
@if($errors->any())
<div class="alert" role="alert" tabindex="-1" data-error-summary><h2>Vérifiez la recherche</h2><ul>@foreach($errors->all() as $error)<li><a href="#member-search">{{ $error }}</a></li>@endforeach</ul></div>
@endif
<form method="get" action="{{ route('admin.assignments.index') }}">
<label for="member-search">Rechercher un nom ou une adresse e-mail</label>
<input id="member-search" type="search" name="q" value="{{ $search }}" maxlength="255"><button>Rechercher</button>
</form>
<ul>
@forelse($users as $member)
<li class="panel">
<h2><a href="{{ route('admin.assignments.edit', $member) }}">{{ $member->name }}</a></h2>
<p>{{ $member->email }} — {{ $member->deactivated_at ? 'Désactivé' : 'Actif' }}</p>
<p>Antennes : @forelse($member->memberships as $membership)<span class="chip">{{ $membership->branch->name }}</span>@empty Aucune appartenance active.@endforelse</p>
<p>Responsabilités : @forelse($member->roleAssignments as $assignment)<span class="chip">{{ $assignment->role->name }} — {{ $scopeNames[$assignment->scope_type][$assignment->scope_id ?? 0] ?? 'Périmètre archivé' }}</span>@empty Aucune responsabilité active.@endforelse</p>
<a href="{{ route('admin.assignments.edit', $member) }}">Gérer les affectations de {{ $member->name }}</a>
</li>
@empty<li>Aucun membre trouvé.</li>@endforelse
</ul>
{{ $users->links() }}
@endsection

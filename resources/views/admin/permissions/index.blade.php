@extends('layouts.app')
@section('title', 'Permissions')
@section('content')
<section data-permission-matrix aria-labelledby="permissions-title">
    <div class="page-heading">
        <h1 id="permissions-title">Permissions</h1>
        <p>{{ $department ? 'Adaptez les droits des rôles de votre département.' : 'Définissez les droits associatifs transmis à tous les départements.' }}</p>
    </div>
    @if($errors->any())
        <div class="alert" role="alert" tabindex="-1" data-error-summary>
            <h2>Vérifiez la matrice avant de l’enregistrer</h2>
            <ul>
                @foreach($errors->getMessages() as $field => $messages)
                    @php
                        $target = 'matrix-form';
                        if (preg_match('/^cells\.([0-9]+)\.([0-9]+)$/', $field, $matches)
                            && $roles->where('slug', '!=', 'technical-admin')->contains('id', (int) $matches[1])
                            && in_array((int) $matches[2], $editablePermissionIds, true)) {
                            $target = 'cell-'.$matches[1].'-'.$matches[2];
                        }
                    @endphp
                    @foreach($messages as $message)
                        <li><a href="#{{ $target }}" data-matrix-error>{{ $message }}</a></li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif
    <nav class="matrix-scopes" aria-label="Périmètre des permissions">
        @if($technical)
            <a href="{{ route('admin.permissions.index') }}" @if(!$department) aria-current="page" @endif>Règles générales</a>
        @endif
        @foreach($departments as $availableDepartment)
            <a href="{{ route('admin.permissions.department', $availableDepartment) }}" @if($department?->id === $availableDepartment->id) aria-current="page" @endif>{{ $availableDepartment->name }}</a>
        @endforeach
    </nav>
    <h2>{{ $department ? 'Département : '.$department->name : 'Règles générales' }}</h2>
    <div class="matrix-tools" data-matrix-tools hidden>
        <div><label for="matrix-search">Rechercher une permission</label><input id="matrix-search" type="search" autocomplete="off" placeholder="Ex. matériel, consulter"></div>
        <div><label for="matrix-domain">Domaine</label><select id="matrix-domain"><option value="">Tous les domaines</option>@foreach($groups as $key => $group)<option value="{{ $key }}">{{ $group['name'] }}</option>@endforeach</select></div>
        <div class="matrix-role-picker"><label for="matrix-role">Rôle affiché</label><select id="matrix-role">@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></div>
    </div>
    <p id="matrix-legend" class="matrix-legend">
        @if($department)
            <strong>↳ Héritée</strong> : applique la règle générale. <strong>✓ Accordée</strong> : autorise dans ce département. <strong>− Refusée</strong> : refuse pour ce rôle.
        @else
            <strong>Case cochée</strong> : permission accordée. <strong>Case vide</strong> : permission refusée.
        @endif
        Les droits de plusieurs rôles se cumulent. Les permissions techniques sont réservées à l’administrateur technique.
    </p>
    <form id="matrix-form" tabindex="-1" method="post" action="{{ $department ? route('admin.permissions.department.update', $department) : route('admin.permissions.global.update') }}">
        @csrf
        @method('put')
        @foreach($roles->where('slug', '!=', 'technical-admin') as $role)
            <input type="hidden" name="represented[{{ $role->id }}]" value="{{ implode(',', $editablePermissionIds) }}">
        @endforeach
        <div class="matrix-scroll" tabindex="0" role="region" aria-label="Matrice des permissions, défilement horizontal" aria-describedby="matrix-legend">
            <table class="matrix-table">
                <caption>Permissions par rôle — {{ $department?->name ?? 'Règles générales' }}</caption>
                <thead><tr><th scope="col">Permission</th>@foreach($roles as $role)<th scope="col" id="matrix-role-{{ $role->id }}" data-role="{{ $role->id }}">{{ $role->name }}<span class="matrix-scope-label">{{ $role->slug === 'technical-admin' ? 'Global' : ($role->allows_department ? ($role->allows_branch ? 'Département et antenne' : 'Département') : 'Antenne') }}</span></th>@endforeach</tr></thead>
                @foreach($groups as $key => $group)
                    <tbody data-matrix-group="{{ $key }}">
                        <tr class="matrix-group"><th scope="rowgroup" colspan="{{ $roles->count() + 1 }}">{{ $group['name'] }}</th></tr>
                        @foreach($group['permissions'] as $permission)
                            <tr data-matrix-permission data-domain="{{ $key }}" data-search="{{ $group['name'].' '.$permission->name.' '.$permission->key }}">
                                <th scope="row" id="matrix-permission-{{ $permission->id }}">{{ $permission->name }}<span class="matrix-key">{{ $permission->key }}</span></th>
                                @foreach($roles as $role)
                                    @php
                                        $editable = $role->slug !== 'technical-admin' && in_array($permission->id, $editablePermissionIds, true);
                                        $granted = $role->slug === 'technical-admin' || (bool) $role->permissions->firstWhere('id', $permission->id)?->pivot->granted;
                                        $state = $department ? ($overrides->get($role->id.':'.$permission->id)?->state ?? 'inherit') : $granted;
                                        $value = $restoreOldInput ? old('cells.'.$role->id.'.'.$permission->id, $department ? $state : false) : $state;
                                        $controlId = 'cell-'.$role->id.'-'.$permission->id;
                                    @endphp
                                    <td data-role="{{ $role->id }}" headers="matrix-role-{{ $role->id }} matrix-permission-{{ $permission->id }}">
                                        @if($editable)
                                            <label for="{{ $controlId }}" class="sr-only">{{ $group['name'] }} : {{ $permission->name }} — {{ $role->name }}</label>
                                            @if($department)
                                                <select id="{{ $controlId }}" name="cells[{{ $role->id }}][{{ $permission->id }}]" aria-describedby="matrix-legend">
                                                    <option value="inherit" @selected($value === 'inherit')>↳ Héritée ({{ $granted ? 'accordée' : 'refusée' }})</option>
                                                    <option value="grant" @selected($value === 'grant')>✓ Accordée</option>
                                                    <option value="deny" @selected($value === 'deny')>− Refusée</option>
                                                </select>
                                            @else
                                                <label class="matrix-check"><input id="{{ $controlId }}" type="checkbox" name="cells[{{ $role->id }}][{{ $permission->id }}]" value="1" @checked(in_array($value, [true, 1, '1'], true))><span data-matrix-state>{{ in_array($value, [true, 1, '1'], true) ? '✓ Accordée' : '− Refusée' }}</span></label>
                                            @endif
                                        @else
                                            <span class="matrix-locked"><span aria-hidden="true">{{ $role->slug === 'technical-admin' ? '✓' : '🔒' }}</span> {{ $role->slug === 'technical-admin' ? 'Accordée · administration technique' : 'Réservée à l’administration technique' }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
        <p data-matrix-empty hidden role="status">Aucune permission ne correspond à votre recherche. Modifiez les filtres.</p>
        <p data-matrix-count aria-live="polite" class="matrix-count"></p>
        <div class="matrix-save"><button type="submit">Enregistrer les modifications</button><span>Les modifications sont enregistrées ensemble et consignées dans le journal d’audit.</span></div>
        <input type="hidden" name="submission_complete" value="1">
    </form>
</section>
@endsection

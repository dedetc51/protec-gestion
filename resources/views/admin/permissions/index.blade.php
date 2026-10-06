@extends('layouts.app')
@section('title', 'Permissions')
@section('content')
<section data-permission-matrix aria-labelledby="permissions-title">
    <div class="page-heading">
        <h1 id="permissions-title">Permissions</h1>
        <p>{{ $department ? 'Adaptez les droits des rôles de votre département.' : 'Définissez les droits associatifs transmis à tous les départements.' }}</p>
    </div>
    @if(session('status'))
        <p class="status-panel" role="status" aria-live="polite">{{ session('status') }}</p>
    @endif
    @if($errors->any())
        <div class="alert" role="alert" tabindex="-1">
            <h2>Vérifiez la matrice avant de l’enregistrer</h2>
            <ul>
                @foreach($errors->getMessages() as $field => $messages)
                    @php
                        $target = preg_match('/^cells\.([0-9]+)\.([0-9]+)$/', $field, $matches) ? 'cell-'.$matches[1].'-'.$matches[2] : 'matrix-form';
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
            <strong>Héritée</strong> : applique la règle générale. <strong>Accordée</strong> : autorise dans ce département. <strong>Refusée</strong> : refuse pour ce rôle.
        @else
            <strong>Case cochée</strong> : permission accordée. <strong>Case vide</strong> : permission refusée.
        @endif
        Les droits de plusieurs rôles se cumulent. Les permissions techniques sont réservées à l’administrateur technique.
    </p>
    <form id="matrix-form" method="post" action="{{ $department ? route('admin.permissions.department.update', $department) : route('admin.permissions.global.update') }}">
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
                                        $value = session()->hasOldInput('cells') ? old('cells.'.$role->id.'.'.$permission->id, $department ? $state : false) : $state;
                                        $controlId = 'cell-'.$role->id.'-'.$permission->id;
                                    @endphp
                                    <td data-role="{{ $role->id }}" headers="matrix-role-{{ $role->id }} matrix-permission-{{ $permission->id }}">
                                        @if($editable)
                                            <label for="{{ $controlId }}" class="sr-only">{{ $group['name'] }} : {{ $permission->name }} — {{ $role->name }}</label>
                                            @if($department)
                                                <select id="{{ $controlId }}" name="cells[{{ $role->id }}][{{ $permission->id }}]" aria-describedby="matrix-legend">
                                                    <option value="inherit" @selected($value === 'inherit')>Héritée ({{ $granted ? 'accordée' : 'refusée' }})</option>
                                                    <option value="grant" @selected($value === 'grant')>Accordée</option>
                                                    <option value="deny" @selected($value === 'deny')>Refusée</option>
                                                </select>
                                            @else
                                                <label class="matrix-check"><input id="{{ $controlId }}" type="checkbox" name="cells[{{ $role->id }}][{{ $permission->id }}]" value="1" @checked(in_array($value, [true, 1, '1'], true))><span data-matrix-state>{{ in_array($value, [true, 1, '1'], true) ? 'Accordée' : 'Refusée' }}</span></label>
                                            @endif
                                        @else
                                            <span class="matrix-locked">{{ $role->slug === 'technical-admin' ? 'Accordée · administration technique' : 'Réservée à l’administration technique' }}</span>
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
<style>
.workspace:has([data-permission-matrix]){min-width:0}
[data-permission-matrix] [hidden]{display:none!important}
.matrix-scopes{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem}.matrix-scopes a{min-height:44px;padding:.65rem .85rem;border:1px solid var(--line);background:white;text-decoration:none}.matrix-scopes a[aria-current]{border-color:var(--red);color:var(--red-dark);font-weight:700}
.matrix-tools{display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1rem}.matrix-tools>div{flex:1;min-width:12rem}.matrix-tools label{display:block;font-weight:600;margin-bottom:.35rem}.matrix-tools input,.matrix-tools select{width:100%;min-height:44px;border:1px solid #9aa6ae;padding:.6rem;background:white;color:var(--ink);font:inherit}.matrix-role-picker{display:none}
.matrix-legend{max-width:75ch;font-size:.95rem;line-height:1.6}.matrix-scroll{max-height:65vh;overflow:auto;border:1px solid var(--line);background:white}.matrix-table{border-collapse:separate;border-spacing:0;text-align:left;font-size:.9rem;width:100%}.matrix-table caption{text-align:left;padding:1rem;font-weight:700;color:var(--ink)}.matrix-table th,.matrix-table td{padding:.65rem .75rem;border-bottom:1px solid var(--line);min-width:13rem}.matrix-table th[scope=row]{position:sticky;left:0;z-index:1;background:white;min-width:15rem}.matrix-table thead th{position:sticky;top:0;z-index:2;background:var(--ink);color:white;vertical-align:top}.matrix-table thead th:first-child{left:0;z-index:3}.matrix-table .matrix-group th{background:#e9edef;border-top:2px solid #9aa6ae;font-weight:700}.matrix-scope-label,.matrix-key{display:block;font-weight:400;font-size:.8rem;margin-top:.25rem}.matrix-key{color:var(--muted)}.matrix-table select{min-height:44px;max-width:100%;padding:.45rem;border:1px solid #9aa6ae;font:inherit;background:white;color:var(--ink)}.matrix-check{display:flex;align-items:center;gap:.6rem;min-height:44px;cursor:pointer}.matrix-check input{width:1.2rem;height:1.2rem;accent-color:var(--green)}.matrix-locked{font-size:.85rem;color:var(--muted)}.matrix-save{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1.5rem}.matrix-save button{background:var(--red);color:white;border:0;padding:.85rem 1rem;font-weight:700;min-height:44px}.matrix-save span,.matrix-count{color:var(--muted);font-size:.9rem}
@media(max-width:750px){.matrix-enhanced .matrix-role-picker{display:block}.matrix-enhanced .matrix-scroll{max-height:none;overflow:visible}.matrix-enhanced .matrix-table,.matrix-enhanced .matrix-table tbody{display:block;width:100%}.matrix-enhanced .matrix-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.matrix-enhanced .matrix-table tr{display:grid;grid-template-columns:1fr}.matrix-enhanced .matrix-table th,.matrix-enhanced .matrix-table td{min-width:0;border-bottom:0}.matrix-enhanced .matrix-table th[scope=row]{position:static;padding-bottom:0}.matrix-enhanced .matrix-table td{border-bottom:1px solid var(--line)}.matrix-enhanced .matrix-table select{width:100%}.matrix-enhanced .matrix-table .matrix-group{border-top:2px solid #9aa6ae}.matrix-enhanced .matrix-table .matrix-group th{border-top:0}.matrix-save button{width:100%}}
</style>
@endsection

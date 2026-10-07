<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title') — Protec-Gestion</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#content">Aller au contenu</a>
<div class="shell">
    <aside id="sidebar" class="sidebar">
        <div class="identity"><span aria-hidden="true">PG</span><strong>Protec-Gestion</strong></div>
        <nav aria-label="Navigation principale">
            <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Accueil</a>
            <a href="{{ route('equipment.index') }}" @if(request()->routeIs('equipment.*')) aria-current="page" @endif>Matériel</a>
            <a href="{{ route('vehicles.index') }}" @if(request()->routeIs('vehicles.*')) aria-current="page" @endif>Véhicules</a>
            @if($administrationLinks !== [])
                <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif>Administration</a>
            @endif
        </nav>
        @if($administrationLinks !== [])
            <nav class="administration-nav" aria-label="Administration de l’association">
                @foreach($administrationLinks as $link)
                    <a href="{{ $link['url'] }}" @if($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </aside>
    <div class="workspace">
        <header>
            <button id="nav-toggle" type="button" aria-expanded="false" aria-controls="sidebar">Menu</button>
            <div><span>{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="text-button">Déconnexion</button></form></div>
        </header>
        <main id="content" tabindex="-1" @class(['administration' => request()->routeIs('admin.*')])>
            @if(request()->routeIs('admin.*') && session('status'))
                <p class="status-panel" role="status" aria-live="polite"><span aria-hidden="true">✓</span> {{ session('status') }}</p>
            @endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>

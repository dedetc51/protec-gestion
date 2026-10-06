<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — Protec-Gestion</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #17202a; background: #f4f6f7; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 1.5rem; }
        main { max-width: 38rem; padding: 2.5rem; background: white; border-top: .4rem solid #b21f2d; box-shadow: 0 1rem 3rem #17202a1c; }
        h1 { margin-top: 0; }
        a { color: #841722; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <p>Erreur @yield('code')</p>
    <h1>Une erreur est survenue</h1>
    <p>@yield('message')</p>
    <p>Veuillez réessayer dans quelques instants ou revenir à l’accueil.</p>
    <a href="{{ url('/') }}">Revenir à l’accueil</a>
</main>
</body>
</html>

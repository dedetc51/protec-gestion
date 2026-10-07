@extends('layouts.app')
@section('title', 'Administration')
@section('content')
<div class="page-heading">
    <h1>Administration de l’association</h1>
    <p>Organisez les antennes, les responsabilités et les droits dans votre périmètre.</p>
</div>
<section aria-labelledby="administration-tools">
    <h2 id="administration-tools">Vos outils d’administration</h2>
    <ul class="administration-tools grid gap-0">
        @foreach($administrationLinks as $link)
            <li class="grid gap-2 py-5 sm:grid-cols-[minmax(0,1fr)_2fr] sm:gap-8">
                <a class="min-h-11 font-semibold" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                <p>{{ $link['description'] }}</p>
            </li>
        @endforeach
    </ul>
</section>
@endsection

{{-- Плитки архива и хвост догрузки (`PagePart`, как `site/catalog-part`): подпись дня ухода («Сегодня», «Вчера», «20.11») —
     над первой плиткой дня; before — плитка перед порцией хвоста, чтобы подпись посреди дня не повторялась. --}}
@php
    $before ??= null;
    $day = $before ? \App\Http\Site\ArchiveController::day($before) : null;
@endphp
@foreach ($offers as $offer)
    @if ($day !== ($label = \App\Http\Site\ArchiveController::day($offer)))
        @php $day = $label; @endphp
        <h2 class="cards-group">{{ $label }}</h2>
    @endif
    <x-offer.archive-card :offer="$offer" :view="$view"/>
@endforeach
@if ($offers instanceof \App\Support\PagePart && $offers->next())
    <div class="cards-tail" data-endless-next="{{ $offers->next() }}" data-rows-next="{{ $offers->after() }}" data-rest="{{ $offers->rest() }}" aria-hidden="true"></div>
@endif

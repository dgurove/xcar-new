{{-- Поиск каталога — и по архиву менеджера (23.11.2026, начальник: «второстепенно: сначала из опубликованных, потом архивные»):
     блоком строк под найденным в продаже, внутри `#catalog-list` — живой поиск подменяет и его. Больше 12 — «Весь архив». --}}
@if (($archived ?? collect())->isNotEmpty())
    <section id="catalog-archive" class="mt-6">
        <div class="cards cards--list" data-controller="ticker">
            <h2 class="cards-group">Архив <span class="nums">{{ $archivedCount }}</span></h2>
            @foreach ($archived as $offer)
                <x-offer.archive-card :offer="$offer" :view="\App\Support\ListView::LIST"/>
            @endforeach
        </div>
        @if ($archivedCount > $archived->count())
            <a href="/offers/archive?q={{ urlencode($filters['q'] ?? '') }}" class="mt-3 inline-flex items-center gap-1 text-sm text-accent-text">Весь архив<x-ui.chevron class="size-3.5"/></a>
        @endif
    </section>
@endif

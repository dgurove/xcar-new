{{-- Архив предложений менеджера (`/offers/archive`, `Site\ArchiveController`, 23.11.2026): тот же ряд пилюль, что у «Предложений»
     (они ведут туда), «Архив» последней и выбран; лупа, чипы города и типа ТС, плитки или строки. Почему ушло — не видно нигде. --}}
<x-ui.shell title="Архив" :heading="false" :back="false" :trail="[['Главная', '/'], ['Предложения', '/offers'], ['Архив']]">
    <div class="hidden md:block">
        <x-ui.section-title level="h1" :count="$total">Архив</x-ui.section-title>
    </div>

    <x-ui.toolbar class="md:mt-5" action="/offers" :pill-query="[]" :pills="$views" :dots="['urgent' => 'urgent', 'fresh' => 'fresh', 'ending' => 'hot']" pill="archive" :pill-default="false" name="archive" :facets="$facets" search="Марка, модель, номер, VIN" search-target="#archive-list">
        <x-slot:pillsExtra><a href="/offers/archive" class="pill" aria-current="true" data-turbo-action="replace">Архив</a></x-slot:pillsExtra>
        <x-slot:extra>
            <x-ui.view-switch :current="$view" :views="\App\Http\Site\ArchiveController::VIEWS"/>
        </x-slot:extra>
    </x-ui.toolbar>

    <div class="mt-6" id="archive-list">
        @if ($offers->isEmpty())
            <x-ui.empty :href="$narrowed ? $facets->resetUrl(['q' => null]) : null" :link="$narrowed ? 'Сбросить фильтры' : null" data-turbo-prefetch="false">
                @if ($narrowed) По этим условиям ничего нет @else За {{ \App\Offers\Offer::ARCHIVE_DAYS }} дней ничего не уходило @endif
            </x-ui.empty>
        @else
            <div id="archive" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker endless">
                @include('site.archive-part', ['before' => null])
            </div>
        @endif
    </div>
</x-ui.shell>

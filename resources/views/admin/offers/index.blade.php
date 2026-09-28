@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.shell title="Предложения" :count="$offers->total()" :phone-heading="false">
    <x-ui.toolbar :sorts="\App\Http\Admin\OfferController::SORTS" :sort="$sort" :pills="\App\Http\Admin\OfferController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="offers">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:actions>
            <a href="/offers/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/offers/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
            <form method="post" action="/offers" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
        </x-slot:actions>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Номер, марка, VIN" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    {{-- Черновики без цены продажи оцениваются в окошке таблицы: цена → «В продажу», и сразу следующий. Кнопка есть, пока
         есть что оценивать, в любом пресете и виде: открывает окошко первого неоценённого. --}}
    @if ($unpriced)
        <div class="mt-3 flex"><a href="/?preset=draft&vid=table&peek=first" class="btn btn-s btn-accent w-full sm:ml-auto sm:w-auto" data-turbo-action="replace">Оценить {{ $unpriced }}</a></div>
    @endif
    <div class="mt-6">
        @if ($offers->isEmpty())
            <x-ui.empty>Предложений нет</x-ui.empty>
        @else
            @if (\App\Support\ListView::isTable($view))
                <x-ui.table id="offers" :view="$view" :open="$peek">
                    <x-slot:head><x-offer.table-head/></x-slot:head>
                    @foreach ($offers as $offer)<x-offer.table-row :offer="$offer"/>@endforeach
                </x-ui.table>
            @else
            <div id="offers" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.crm-card :offer="$offer"/>@endforeach
            </div>
            @endif
            <div class="mt-8"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>
        @endif
    </div>
</x-ui.shell>

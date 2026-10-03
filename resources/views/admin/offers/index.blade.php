@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.shell title="Предложения" :count="$offers->total()" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :sorts="$sorts" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="offers" :facets="$facets" search="Номер, марка, VIN, убыток">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:actions>
            @if (auth()->user()->canCrmMail())<a href="/offers/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/offers/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>@endif
            <form method="post" action="/offers" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            <x-ui.empty>Предложений нет</x-ui.empty>
        @else
            @if (\App\Support\ListView::isTable($view))
                <x-ui.table id="offers" :view="$view">
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
    {{-- Окно писем для карточки «Письма» в карточке строки. --}}
    <x-mail.window/>
</x-ui.shell>

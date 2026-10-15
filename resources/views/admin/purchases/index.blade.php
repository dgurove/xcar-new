{{-- Слева сортировка, справа действия экрана: настройка «Кому что не показывать» (тихая, с числом ограничений) и
     «+ Закупка» — не пилюлей слева, где её читали как фильтр списка. --}}
<x-ui.shell title="Закупки">
    <div class="mb-6 flex flex-wrap items-center gap-2" data-controller="sheet">
        <x-ui.sort :sort="$sort" :url="fn ($v) => \App\Support\Sort::url($v)" name="purchases"/>
        <a href="/purchases/limits" class="btn btn-s btn-quiet ml-auto rounded-full">Кому что не показывать@if ($restricted) <span class="nums text-ink-dim">{{ $restricted }}</span>@endif</a>
        <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open" aria-label="Новая закупка"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline"> Закупка</span></button>
        <x-ui.sheet id="purchase-new" title="Новая закупка">
            <form method="post" action="/purchases" class="flex flex-col gap-4">
                @csrf
                <div class="fields">
                    <x-ui.field name="title" label="Название для нас" placeholder="Carcade, сентябрь"/>
                    <x-ui.field name="supplier" label="Поставщик" placeholder="Carcade"/>
                    <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local" default-time="17:30"/>
                </div>
                <div class="sheet-foot"><x-ui.button block>Создать</x-ui.button></div>
            </form>
        </x-ui.sheet>
    </div>
    <div class="list">
        @forelse ($purchases as $p)
            <a href="/purchases/{{ $p->number }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="truncate">{{ $p->title ?: $p->publicTitle() }}</div>
                    <div class="row-sub">
                        <span class="nums">№ {{ $p->number }}</span>
                        @if ($p->closed())<span>приём закрыт {{ $p->offers_close_at->translatedFormat('j M, H:i') }}</span>
                        @else<span class="{{ $p->state->tone() === 'open' ? 'text-accent-text' : '' }}">{{ mb_strtolower($p->state->label()) }}</span>@if ($p->offers_close_at)<span>до {{ $p->offers_close_at->translatedFormat('j M, H:i') }}</span>@endif
                        @endif
                    </div>
                </div>
                <span class="nums shrink-0 text-ink-dim">{{ $p->cars_count }} ТС</span>
                <x-ui.chevron/>
            </a>
        @empty
            <x-ui.empty>Закупок ещё нет</x-ui.empty>
        @endforelse
    </div>
</x-ui.shell>

<x-ui.shell title="Закупки">
    <div class="mb-6 flex items-center gap-2" data-controller="sheet">
        <x-ui.pill tone="plain" href="/purchases/limits">Кому что не показывать@if ($restricted) <span class="badge">{{ $restricted }}</span>@endif</x-ui.pill>
        <x-ui.sort :sort="$sort" :url="fn ($v) => \App\Support\Sort::url($v)" name="purchases"/>
        <button type="button" class="btn btn-s btn-accent ml-auto rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Закупка</button>
        <x-ui.sheet id="purchase-new" title="Новая закупка">
            <form method="post" action="/purchases" class="flex flex-col gap-4">
                @csrf
                <div class="fields">
                    <x-ui.field name="title" label="Название для нас" placeholder="Carcade, сентябрь" autofocus/>
                    <x-ui.field name="supplier" label="Поставщик" placeholder="Carcade"/>
                    <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local"/>
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
                        @if ($p->closed())<span>приём закрыт с {{ $p->offers_close_at->translatedFormat('j M, H:i') }}</span>
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

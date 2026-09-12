<x-ui.shell title="Закупки">
    <div class="mb-6 flex items-center gap-2" data-controller="sheet">
        <x-ui.pill tone="plain" href="/zakupki/ogranicheniya">Кому что не показывать{{ $restricted ? ' · '.$restricted : '' }}</x-ui.pill>
        <button type="button" class="btn btn-s btn-accent ml-auto rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Закупка</button>
        <x-ui.sheet id="purchase-new" title="Новая закупка">
            <form method="post" action="/zakupki" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="title" label="Название для нас" placeholder="Carcade, сентябрь" autofocus/>
                <x-ui.field name="supplier" label="Поставщик" placeholder="Carcade"/>
                <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local"/>
                <x-ui.button block>Создать</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($purchases as $p)
            <a href="/zakupki/{{ $p->number }}" class="row items-start">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $p->title ?: $p->publicTitle() }} <span class="nums text-sm font-normal text-ink-dim">№ {{ $p->number }}</span></div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-sm">
                        <x-ui.pill :tone="$p->state->tone() === 'open' ? 'open' : ($p->state->tone() === 'plain' ? 'plain' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $p->state->label() }}</x-ui.pill>
                        <span class="chip">{{ $p->cars_count }} машин</span>
                        @if ($p->offers_close_at)<span class="chip">до {{ $p->offers_close_at->translatedFormat('j M, H:i') }}</span>@endif
                    </div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        @empty
            <x-ui.empty>Закупок ещё нет.</x-ui.empty>
        @endforelse
    </div>
</x-ui.shell>

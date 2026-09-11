<x-ui.shell title="Закупки" :wide="true">
    <div class="mb-4 flex items-center gap-2" data-controller="sheet">
        <a href="/admin/zakupki/ogranicheniya" class="chip">Кому что не показывать{{ $restricted ? ' · '.$restricted : '' }}</a>
        <x-ui.button type="button" size="sm" class="ml-auto" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Закупка</x-ui.button>
        <x-ui.sheet id="purchase-new" title="Новая закупка">
            <form method="post" action="/admin/zakupki" class="flex flex-col gap-4">
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
            <a href="/admin/zakupki/{{ $p->number }}" class="row items-start">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">№ {{ $p->number }} · {{ $p->title ?: $p->publicTitle() }}</div>
                    <div class="mt-1 flex flex-wrap gap-1.5 text-sm">
                        <span class="chip {{ match($p->state->tone()) { 'open' => 'bg-open-soft text-open', 'plain' => '', default => 'bg-closed-soft text-closed' } }}">{{ $p->state->label() }}</span>
                        <span class="chip tabular-nums">{{ $p->cars_count }} машин</span>
                        @if ($p->offers_close_at)<span class="chip tabular-nums">до {{ $p->offers_close_at->translatedFormat('j M, H:i') }}</span>@endif
                    </div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        @empty
            <div class="py-24 text-center text-ink-muted">Закупок ещё нет</div>
        @endforelse
    </div>
</x-ui.shell>

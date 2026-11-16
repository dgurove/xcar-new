{{-- «Закупки» — первый раздел колеса «Предложений» (16.11.2026, `PurchaseController::tab`): заголовок — колесо вкладок.
     Слева сортировка, справа действия экрана: настройка «Кому что не показывать» (тихая, с числом ограничений) и
     «+ Закупка» — не пилюлей слева, где её читали как фильтр списка. --}}
<x-ui.shell :title="$presets['purchases'] ?? 'Закупки'" :heading="false">
    {{-- Шапка — одна на вкладки колеса (`x-admin.tab-head`, 17.11.2026): «Кому что не показывать» и «+ Закупка» — действиями в строке
         названия, сортировка — в ряду кнопок; чипов у закупок нет. --}}
    <x-admin.tab-head :presets="$presets" preset="purchases" :counts="$counts" :sections="$sections" :sort="$sort" name="purchases">
        <x-slot:actions>
            {{-- На телефоне — кружок со значком: подписью кнопка уходила второй строкой под название. --}}
            <a href="/purchases/limits" class="btn btn-s btn-quiet shrink-0 rounded-full" aria-label="Кому что не показывать" title="Кому что не показывать"><x-ui.icon name="eye-off" class="size-4"/><span class="hidden sm:inline">Кому что не показывать@if ($restricted) <span class="nums text-ink-dim">{{ $restricted }}</span>@endif</span></a>
            <div class="contents" data-controller="sheet">
                <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open" aria-label="Новая закупка"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline"> Закупка</span></button>
                <x-ui.sheet id="purchase-new" title="Новая закупка">
                    <form method="post" action="/purchases" class="flex flex-col gap-4">
                        @csrf
                        <div class="fields">
                            <x-ui.field name="title" label="Название для нас" placeholder="Carcade, сентябрь"/>
                            <x-ui.field name="supplier" label="Поставщик" placeholder="Carcade"/>
                            <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local" default-time="17:30"/>
                        </div>
                        <x-ui.sheet-foot>Создать</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        </x-slot:actions>
    </x-admin.tab-head>
    <div class="list mt-6">
        @forelse ($purchases as $p)
            <a href="/purchases/{{ $p->number }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="truncate">{{ $p->title ?: $p->publicTitle() }}</div>
                    <div class="row-sub">
                        <span class="nums">№ {{ $p->number }}</span>
                        @if ($p->closed())<span>приём закрыт {{ \App\Support\Ago::html($p->offers_close_at, true, words: false) }}</span>
                        @else<span class="{{ $p->state->tone() === 'open' ? 'text-accent-text' : '' }}">{{ mb_strtolower($p->state->label()) }}</span>@if ($p->offers_close_at)<span>до {{ \App\Support\Ago::html($p->offers_close_at, true, words: false) }}</span>@endif
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

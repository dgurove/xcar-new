{{-- Сам список заявок: таблица с окошком или карточки, в конце хвост со следующей порцией — его ловит
     endless_controller. Страниц у заявок нет. Этим же куском отвечает лента (заголовок X-List). --}}
@php use App\Support\ListView; @endphp
@if ($requests->isEmpty())
    <x-ui.empty class="py-6">{{ request('q') || request('vendor') || request('yard') || request('mine') ? 'Ничего не нашлось' : 'Всё сделано' }}</x-ui.empty>
@elseif ($view === ListView::TABLE)
    <x-ui.table id="requests-table">
        <x-slot:head>
            <tr><th>Тип</th><th class="grow">Марка, модель</th><th class="hidden sm:table-cell">№ убытка</th><th class="num">Срок</th><th class="hidden sm:table-cell">Исполнитель</th><th class="hidden sm:table-cell">Парковка</th></tr>
        </x-slot:head>
        @foreach ($requests as $r)<x-park.request-row :req="$r"/>@endforeach
    </x-ui.table>
@else
    @foreach ($requests as $r)<x-park.request-card :req="$r"/>@endforeach
@endif
@if ($requests->hasMorePages())
    <div class="col-span-full py-6 text-center text-sm text-ink-dim" data-endless-next="{{ $requests->appends(request()->query())->nextPageUrl() }}">Ещё заявки…</div>
@endif

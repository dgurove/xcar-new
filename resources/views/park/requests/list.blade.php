{{-- Сам список заявок: таблица с карточкой или карточки, в конце хвост со следующей порцией — его ловит
     endless_controller. Страниц у заявок нет. Этим же куском отвечает лента (заголовок X-List). Сроков и исполнителя
     у заявок нет (10.10.2026): столбцы — название, когда пришла, от кого, номер убытка, тип, парковка. --}}
@php
    use App\Support\ListView;
    $arrive ??= [];
    $sort ??= \App\Support\Sort::from(request('sort'), \App\Http\Park\RequestController::SORTS, \App\Http\Park\RequestController::SORT);
@endphp
@if ($requests->isEmpty())
    <x-ui.empty class="py-6">{{ request('q') || request('vendor') || request('yard') ? 'Ничего не нашлось' : 'Всё сделано' }}</x-ui.empty>
@elseif (ListView::isTable($view))
    @php $columns = \App\Http\Park\RequestController::table(trim((string) request('q')) !== '' || ! \App\Park\RequestType::tryFrom((string) request('preset', \App\Park\RequestType::Intake->value))); @endphp
    <x-ui.table :table="$columns" id="requests-table" :view="$view" :rest="$columns->rest()">
        <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
        @foreach ($requests as $r)<x-park.request-row :req="$r" :table="$columns" :arrive="$arrive[$r->vehicle_id] ?? null"/>@endforeach
    </x-ui.table>
@else
    @foreach ($requests as $r)<x-park.request-card :req="$r" :arrive="$arrive[$r->vehicle_id] ?? null"/>@endforeach
@endif
@if ($requests->hasMorePages())
    <div class="col-span-full py-6 text-center text-sm text-ink-dim" data-endless-next="{{ $requests->appends(request()->query())->nextPageUrl() }}">Ещё заявки…</div>
@endif

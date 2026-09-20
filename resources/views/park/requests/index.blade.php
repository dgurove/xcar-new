{{-- Главная стоянки — заявки: пресеты «Просрочено», «Нужно позвонить», типы, сортировка, поиск по ТС, вендор, площадка и «Мои» в фильтрах;
     три вида — плитки/строки x-park.request-card, таблица x-park.request-row с окошком. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $requests->total()); @endphp
<x-ui.shell title="Заявки" :count="$requests->total()" :phone-heading="false">
    <x-ui.toolbar :sorts="\App\Http\Park\RequestController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['overdue' => !empty($counts['overdue']) ? 'pill-danger' : '']" :hidden="array_filter(['vendor' => request('vendor'), 'yard' => request('yard'), 'mine' => request('mine'), ListView::PARAM => request(ListView::PARAM)])" name="requests">
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
            <a href="/requests/new" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Заявка</span></a>
            <a href="/requests/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/requests/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
        </x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Убыток, VIN, госномер, марка" enterkeyhint="search">
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
            <select name="yard" class="field-input"><option value="">Все парковки</option>@foreach ($yards as $id => $name)<option value="{{ $id }}" @selected((string) request('yard') === (string) $id)>{{ $name }}</option>@endforeach</select>
            <x-ui.check name="mine" :checked="request()->boolean('mine')">Мои</x-ui.check>
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($requests->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' || request('vendor') || request('yard') || request('mine') ? 'Ничего не нашлось' : 'Всё сделано' }}</x-ui.empty>
    @elseif ($view === ListView::TABLE)
        <x-ui.table id="requests" class="mt-6">
            <x-slot:head>
                <tr><th>Тип</th><th class="grow">Марка, модель</th><th class="hidden sm:table-cell">№ убытка</th><th class="num">Срок</th><th class="hidden sm:table-cell">Исполнитель</th><th class="hidden sm:table-cell">Парковка</th></tr>
            </x-slot:head>
            @foreach ($requests as $r)<x-park.request-row :req="$r"/>@endforeach
        </x-ui.table>
    @else
        <div class="mt-6 {{ ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($requests as $r)<x-park.request-card :req="$r"/>@endforeach
        </div>
    @endif
    <div class="mt-8"><x-ui.pager :of="$requests" :sizes="ListView::perSizes($view)"/></div>
</x-ui.shell>

{{-- Главная стоянки — заявки: пилюли «Просрочено», «Нужно позвонить» и типы (каждая — пока такие заявки есть),
     сортировка, поиск по ТС, вендор, парковка и «Мои» в фильтрах; три вида — плитки/строки x-park.request-card,
     таблица x-park.request-row с окошком. Страниц нет: список подгружается при листании (endless). --}}
@php use App\Support\ListView; @endphp
<x-ui.shell title="Заявки" :count="$requests->total()" :phone-heading="false">
    <x-ui.toolbar :sorts="\App\Http\Park\RequestController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['overdue' => !empty($counts['overdue']) ? 'pill-danger' : '']" :hidden="array_filter(['vendor' => request('vendor'), 'yard' => request('yard'), 'mine' => request('mine'), ListView::PARAM => request(ListView::PARAM)])" name="requests">
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
        </x-slot:extra>
        {{-- «+» кружком понятен и без слова, а «Из писем» названо словами: на телефоне два слова в ряд не влезали. --}}
        <x-slot:actions>
            <a href="/requests/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full"><x-ui.icon name="mail" class="size-4"/>Из писем<x-ui.badge href="/requests/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
            <a href="/requests/new" class="btn btn-s btn-accent btn-round shrink-0" aria-label="Новая заявка"><x-ui.icon name="plus" class="size-5"/></a>
        </x-slot:actions>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Убыток, VIN, госномер, марка" enterkeyhint="search">
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
            <select name="yard" class="field-input"><option value="">Все парковки</option>@foreach ($yards as $id => $name)<option value="{{ $id }}" @selected((string) request('yard') === (string) $id)>{{ $name }}</option>@endforeach</select>
            <x-ui.check name="mine" :checked="request()->boolean('mine')">Мои</x-ui.check>
        </x-slot:filters>
    </x-ui.toolbar>
    <div id="requests" class="mt-6 {{ $view === ListView::TABLE ? '' : ListView::containerClass($view) }}" data-controller="endless ticker">@include('park.requests.list')</div>
</x-ui.shell>

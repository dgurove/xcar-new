{{-- Главная стоянки — заявки: пилюли «Просрочено», «Нужно позвонить» и типы (каждая — пока такие заявки есть),
     сортировка, лупа (по ТС), чипы вендор, парковка, тип ТС и «Мои»; три вида — плитки/строки x-park.request-card,
     таблица x-park.request-row с окошком. Страниц нет: список подгружается при листании (endless). --}}
@php use App\Support\ListView; @endphp
<x-ui.shell title="Заявки" :count="$requests->total()" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :sorts="\App\Http\Park\RequestController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['overdue' => !empty($counts['overdue']) ? 'pill-danger' : '']" name="requests" :facets="$facets" search="Убыток, VIN, госномер, марка" search-target="#requests" :q="$q">
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
        </x-slot:extra>
        {{-- «+» кружком понятен и без слова, а «Из писем» названо словами: на телефоне два слова в ряд не влезали. --}}
        <x-slot:actions>
            <a href="/requests/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full"><x-ui.icon name="mail" class="size-4"/>Из писем<x-ui.badge href="/requests/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
            <a href="/requests/new" class="btn btn-s btn-accent btn-round shrink-0" aria-label="Новая заявка"><x-ui.icon name="plus" class="size-5"/></a>
        </x-slot:actions>
    </x-ui.toolbar>
    <div id="requests" class="mt-6 {{ ListView::isTable($view) ? '' : ListView::containerClass($view) }}" data-controller="endless ticker">@include('park.requests.list')</div>
</x-ui.shell>

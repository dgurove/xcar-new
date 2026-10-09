{{-- «Наличие»: пилюли «На парковке» и «Выданы» (выданные — архив дел под месяцами выдачи, свежие сверху, свои столбцы,
     чипы «Месяц» и «Деньги»). Чипы — вендор, парковка,
     тип ТС и «Без ставки», лупа ищет по ходу набора (по всем состояниям) и адрес не меняет; вид — таблица с карточкой
     или карточки. Справа — «Скачать»: тот же список файлом с колонками таблицы парковки (сверка с вендором). --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell title="Наличие" :count="$vehicles->total()" :detail="$detail" :phone-heading="false">
    <x-ui.toolbar :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="vehicles" :facets="$facets"
                  search="Номер, VIN, госномер, СТС, марка" search-target="#cars" :q="$q">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        @if (auth()->user()->canManagePark())
            <x-slot:actions>
                <a href="/cars/export?{{ http_build_query(request()->except(['peek', 'page'])) }}" class="btn btn-s btn-quiet btn-round shrink-0" download data-turbo="false" aria-label="Скачать таблицей" title="Скачать таблицей"><x-ui.icon name="download" class="size-5"/></a>
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <div class="mt-4" id="cars">@include('park.vehicles.list')</div>
</x-ui.shell>

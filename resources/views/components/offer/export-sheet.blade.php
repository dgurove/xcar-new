{{-- Окно «Выгрузка» над списком предложений (21.10.2026, владелец: «выбирать, какие столбцы включить, и скопировать,
     например, только номера убытков и даты заведения — удобно и красиво»): столбцы — пилюлями-галками, под ними образец
     первых строк таблицей, подвалом «Скопировать» и «Excel». Данные — `{url}?format=json&cols=` тем же адресом списка при
     каждом открытии и смене столбцов (`export_controller`): буфер — из пришедшего, в том же нажатии. Столбцы — `Offers\Export\OfferColumns`,
     выбор помнит браузер по списку. Список — `vendor` или `crm`. --}}
@props(['url', 'list', 'id' => 'export-sheet'])
@php $cols = \App\Offers\Export\OfferColumns::for($list, auth()->user()); @endphp
<div class="contents" data-controller="sheet export" data-export-url-value="{{ $url }}" data-export-list-value="{{ $list }}">
    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open export#open" aria-controls="{{ $id }}" aria-label="Выгрузка" title="Выгрузка"><x-ui.icon name="download" class="size-5"/></button>
    <x-ui.sheet :id="$id" title="Выгрузка" anchor class="sheet-export">
        <div class="facet-tools">
            <span class="text-sm text-ink-dim">Столбцы</span>
            <button type="button" class="facet-all" data-export-target="all" data-action="export#all">Снять все</button>
        </div>
        <div class="export-cols">
            @foreach ($cols as $c)
                <label class="pill export-col"><input type="checkbox" class="sr-only" value="{{ $c['key'] }}" data-label="{{ $c['label'] }}" @checked($c['on']) data-export-target="box" data-action="change->export#pick">{{ $c['label'] }}</label>
            @endforeach
        </div>
        <div class="export-sample box-nested" data-export-target="sample">
            <div class="skeleton h-4 w-3/4"></div>
            <div class="skeleton mt-2 h-4 w-2/3"></div>
            <div class="skeleton mt-2 h-4 w-1/2"></div>
        </div>
        <div class="facet-submit flex gap-2">
            <button type="button" class="btn btn-quiet flex-1" data-export-target="copy" data-action="export#copy" disabled><x-ui.icon name="copy" class="size-5"/> Скопировать</button>
            {{-- Файл — шторкой документов: в установленном приложении на iPhone download открывал Quick Look без выхода. --}}
            <x-ui.doc :doc="['url' => $url, 'type' => 'sheet', 'name' => 'offers.xlsx', 'label' => 'Выгрузка, Excel']" class="btn btn-accent flex-1" data-export-target="file" data-action="export#file"><x-ui.icon name="download" class="size-5"/> Excel</x-ui.doc>
        </div>
    </x-ui.sheet>
</div>

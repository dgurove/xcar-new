{{-- Карточка документа рядом со списком «Счетов и актов»: тело — `closing-body`, «···» — `closing-menu`, ⤢ — страница
     документа со всеми ТС (`/money/closing/{ключ}`). --}}
<x-ui.detail>
    <x-ui.row-card :href="'/money/closing/'.$doc->key" :title="$doc->name" :head="false" :photo="false" :action="false">
        <x-slot:tools>@include('park.money.closing-menu')</x-slot:tools>
        @include('park.money.closing-body')
    </x-ui.row-card>
</x-ui.detail>

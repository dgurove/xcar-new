{{-- Контрагент стоянки: общие поля x-billing.party-fields, «Убрать» — пока на нём нет счетов. --}}
<form method="post" action="{{ $party ? '/money/parties/'.$party->id : '/money/parties' }}" class="flex flex-col gap-4">
    @csrf @if ($party) @method('put') @endif
    <x-billing.party-fields :party="$party"/>
    <x-ui.button block>Сохранить</x-ui.button>
</form>
@if ($party && ! $party->is_self && ! $party->invoices_count)
    <form method="post" action="/money/parties/{{ $party->id }}" class="mt-2" data-turbo-confirm="Убрать контрагента «{{ $party->name }}»?">@csrf @method('delete')<x-ui.button variant="ghost" block>Убрать</x-ui.button></form>
@endif

{{-- Контрагент стоянки: общие поля x-billing.party-fields, «Убрать» — пока на нём нет счетов. Незаписанный черновик
     вендора (`Party::forVendor(…, false)`) с `vendor` — новый контрагент, который сразу становится реквизитами вендора. --}}
@php $saved = $party?->exists; @endphp
<form method="post" action="{{ $saved ? '/money/parties/'.$party->id : '/money/parties' }}" class="flex flex-col gap-4">
    @csrf @if ($saved) @method('put') @endif
    @if (! $saved && ($vendor ?? null))<input type="hidden" name="vendor_id" value="{{ $vendor->id }}">@endif
    <x-billing.party-fields :party="$party" :vat="! $party?->is_self"/>
    <x-ui.button block>Сохранить</x-ui.button>
</form>
@if ($saved && ! $party->is_self && ! $party->invoices_count)
    <form method="post" action="/money/parties/{{ $party->id }}" class="mt-2" data-turbo-confirm="Убрать контрагента «{{ $party->name }}»?">@csrf @method('delete')<x-ui.button variant="ghost" block>Убрать</x-ui.button></form>
@endif

{{-- Чего нет ни у предложения, ни в тексте — вписать тут же, уходит вместе с «Всё верно, сохранить» (05.10.2026). --}}
<div class="valuation-gaps grid grid-cols-1 gap-2 px-4 pb-3 sm:grid-cols-2">
    @if (in_array('vin', $gaps, true))
        <input type="text" name="vin[{{ $offer->id }}]" maxlength="17" class="field-input field-s uppercase" placeholder="VIN" aria-label="VIN {{ $offer->titleWithYear() }}"
               autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false">
    @endif
    @if (in_array('city', $gaps, true))
        <x-ui.combobox name="city[{{ $offer->id }}]" label="" url="/reference/settlements" placeholder="Город" aria-label="Город {{ $offer->titleWithYear() }}"/>
    @endif
</div>

{{-- «+ Интерес» (13.10.2026, владелец: «удобное подтверждение за менеджера есть, а в галерее — интерес за менеджера, если он
     устно сказал, или на себя»): та же шторка, что `x-offer.bid-for`. Встаёт в список интереса с «внёс …»
     (`InterestController::store` → `RegisterInterest`), уведомлений не шлёт. --}}
@props(['offer'])
@php $id = 'interest-for-'.$offer->number; @endphp
<div class="contents" data-controller="sheet">
    <x-ui.button type="button" size="sm" variant="ghost" class="self-start" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Интерес</x-ui.button>
    <x-ui.sheet :id="$id" title="Интерес" :open="$errors->has('manager_id') && old('interest_for') == $offer->id">
        <form method="post" action="/offers/{{ $offer->number }}/interests" class="flex flex-col gap-3" data-controller="reveal">
            @csrf<input type="hidden" name="interest_for" value="{{ $offer->id }}">
            <div class="segment">
                <label><input type="radio" name="who" value="manager" @checked(old('who', 'manager') === 'manager') data-action="reveal#pick"><span>За менеджера</span></label>
                <label><input type="radio" name="who" value="me" @checked(old('who') === 'me') data-action="reveal#pick"><span>На себя</span></label>
            </div>
            <div class="fields">
                <div class="contents" data-reveal-target="pane" data-reveal-key="manager" @if (old('who') === 'me') hidden @endif>
                    <x-ui.combobox name="manager_id" label="Менеджер" url="/reference/managers" placeholder="Имя или телефон" :value="old('manager_id')"/>
                </div>
                <x-ui.field name="comment" label="Комментарий" :value="old('comment')" placeholder="Сказал по телефону" id="{{ $id }}-comment"/>
            </div>
            <div class="sheet-foot"><x-ui.button type="submit" block>Внести интерес</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>

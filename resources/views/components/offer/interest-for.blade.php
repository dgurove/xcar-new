{{-- «+ Интерес» (13.10.2026, владелец: «как подтверждение за менеджера»): последней строкой группы интереса, как «Отдать ещё»
     у оценок. Шторка «За менеджера | На себя», кнопка называет исход. Встаёт в список с «внёс …»
     (`InterestController::store` → `RegisterInterest`), уведомлений не шлёт. --}}
@props(['offer', 'trigger' => true])
@php $id = 'interest-for-'.$offer->number; @endphp
<div class="contents" data-controller="sheet">
    @if ($trigger)<button type="button" class="row w-full text-left text-accent-text" data-action="sheet#open"><x-ui.row-icon name="plus" tone="accent" size="s"/><span class="min-w-0 flex-1">Интерес</span></button>@endif
    <x-ui.sheet :id="$id" title="Интерес" :open="$errors->has('manager_id') && old('interest_for') == $offer->id">
        <form method="post" action="/offers/{{ $offer->number }}/interests" class="flex flex-col gap-3" data-controller="reveal" data-reveal-label-selector-value="#{{ $id }}-submit">
            @csrf<input type="hidden" name="interest_for" value="{{ $offer->id }}">
            <x-ui.segment name="who" :options="['manager' => 'За менеджера', 'me' => 'На себя']" :value="old('who', 'manager')" pick :reveal="['manager' => 'Внести за менеджера', 'me' => 'Отметить интерес']"/>
            <div class="fields">
                <div class="contents" data-reveal-target="pane" data-reveal-key="manager" @if (old('who') === 'me') hidden @endif>
                    <x-ui.combobox name="manager_id" label="Менеджер" url="/reference/managers" placeholder="Имя или телефон" :value="old('manager_id')" :id="$id.'-manager'"/>
                </div>
                <x-ui.field name="comment" label="Комментарий" :value="old('comment')" placeholder="Сказал по телефону" id="{{ $id }}-comment"/>
            </div>
            <x-ui.sheet-foot type="submit" id="{{ $id }}-submit">{{ old('who') === 'me' ? 'Отметить интерес' : 'Внести за менеджера' }}</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>

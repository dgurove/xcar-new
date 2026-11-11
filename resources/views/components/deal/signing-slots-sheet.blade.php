{{-- «Поставщик предложил другое время» (`Offers\Signing`, 11.11.2026): адрес филиала и свободное время из письма — менеджер выберет
     плиткой. Адрес по умолчанию — у сделки, филиал вендора, прошлый у вендора (`Signing::defaultAddress`). Строки времени — `repeater`. --}}
@props(['id', 'deal', 'exit'])
@php
    $mine = old('_sheet') === $id;
    $slots = $mine ? array_values(array_filter((array) old('slots'))) : [];
    $slots = $slots ?: [''];
    $address = $mine ? old('address') : \App\Offers\Signing::defaultAddress($deal);
@endphp
<x-ui.sheet :id="$id" title="Время, что предложил поставщик" tall>
    <form method="post" action="/work/deals/{{ $deal->id }}/signing-slots" class="flex flex-col gap-4" data-controller="repeater">
        @csrf
        <input type="hidden" name="exit" value="{{ $exit->id }}">
        @if ($mine && $errors->first('slots'))<x-ui.flash tone="danger">{{ $errors->first('slots') }}</x-ui.flash>@endif
        @if ($deal->signing_at)<p class="text-sm text-ink-muted">Просили {{ \App\Offers\Signing::short($deal->signing_at) }}, поставщик предложил другое</p>@endif
        <div class="fields">
            <x-ui.field name="address" label="Адрес филиала" :id="$id.'-address'" :value="$address" placeholder="Москва, ул. Шаболовка, 31"/>
        </div>
        <div>
            <p class="field-label mb-2">Время для записи</p>
            <div class="flex flex-col gap-2" data-repeater-target="list">
                @foreach ($slots as $i => $slot)
                    <div class="flex items-center gap-2" data-row>
                        <div class="min-w-0 flex-1"><x-ui.date :name="'slots['.$i.']'" :id="$id.'-slot-'.$i" :value="$slot" time default-time="10:00" :min="now()"/></div>
                        @unless ($loop->first)<button type="button" class="btn btn-ghost btn-s shrink-0 px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать время"><x-ui.icon name="x" class="size-5"/></button>@endunless
                    </div>
                @endforeach
            </div>
            <template data-repeater-target="template">
                <div class="flex items-center gap-2" data-row>
                    <div class="min-w-0 flex-1"><x-ui.date name="slots[__i__]" :id="$id.'-slot-__i__'" time default-time="10:00" :min="now()"/></div>
                    <button type="button" class="btn btn-ghost btn-s shrink-0 px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать время"><x-ui.icon name="x" class="size-5"/></button>
                </div>
            </template>
            <button type="button" class="btn btn-quiet btn-s mt-2" data-action="repeater#add"><x-ui.icon name="plus" class="size-4"/>Ещё время</button>
        </div>
        <x-ui.sheet-foot type="submit">Показать менеджеру</x-ui.sheet-foot>
    </form>
</x-ui.sheet>

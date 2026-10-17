{{-- «Кому показывать» — строка-сводка, нажатие — шторка со списком (12.10.2026): один кусок у модуля «Показ» и у шторки
     «Отменить сделку → В продажу». mode `waves` — волны «кто → когда» (`audience_rules`), `list` — белый список сделки без
     времени (`deal_audience`); значение — JSON в скрытом поле, form — id формы, когда поле вне неё. --}}
@props(['offer', 'form' => null, 'mode' => 'waves', 'key' => 'show'])
@php
    use App\Offers\{AudienceRules, DealAudience};
    $options = once(fn () => AudienceRules::options());
    if ($mode === 'list') {
        $name = 'deal_audience';
        $value = DealAudience::toJson($offer);
        $who = DealAudience::summary($offer);
        $rules = null;
    } else {
        $name = 'audience_rules';
        $value = $offer->audience_rules ? json_encode($offer->audience_rules) : '';
        $rules = AudienceRules::of($offer);
        $who = AudienceRules::summary($rules);
    }
@endphp
<div {{ $attributes->class(['field']) }}>
    <span class="field-label">Кому показывать</span>
    <div data-controller="audience sheet" data-audience-mode-value="{{ $mode }}" data-audience-options-value="{{ json_encode($options) }}" @if ($rules) data-audience-effective-value="{{ json_encode($rules) }}" @endif>
        <input type="hidden" name="{{ $name }}" value="{{ $value }}" @if ($form) form="{{ $form }}" @endif data-audience-target="rules">
        <button type="button" class="field-input flex w-full items-center gap-3 text-left" data-action="sheet#open">
            <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
            <span class="min-w-0 flex-1" data-audience-target="summary">{{ $who }}</span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </button>
        <x-ui.sheet id="audience-{{ $offer->number }}-{{ $key }}" title="Кому показывать">
            <x-audience.editor/>
            {{-- «Сохранить» в шторке (17.10.2026, владелец: «не сохраняется, не хватает кнопки сохранить» — «Сохранить
                 изменения» оставалась под шторкой): форма уходит сразу. В чужой форме (шторка «Отменить сделку») — «Готово»,
                 сохраняет её кнопка. --}}
            @if ($form)
                <x-ui.sheet-foot type="button" foot="mt-4" data-action="audience#save sheet#close">Сохранить</x-ui.sheet-foot>
            @else
                <x-ui.sheet-foot type="button" foot="mt-4" variant="secondary" data-action="sheet#close">Готово</x-ui.sheet-foot>
            @endif
        </x-ui.sheet>
    </div>
</div>

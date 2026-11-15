{{-- «Кому показывать» одним куском (12.11.2026): поле с окном `x-audience.editor`. inline — окно сразу полями своим блоком под
     группой полей, не строкой внутри `.fields` (внутри шторки: «Показ», «Опубликовать для других», «Вернуть в продажу»,
     переоценка, вендор — шторки в шторке нет); иначе — строка-сводка,
     нажатие — шторка с окном (плашка «Без подтверждений», карточка «Показ» страницы). value — что хранится (JSON; пусто — как у
     вендора или всем сразу), rules — что действует сейчас (с ним окно открывается); form — id формы, когда поле вне неё;
     с form у строки-сводки «Сохранить» отправляет её сразу, без — «Готово». deal — публикация машины в сделке. Слот end — поле
     конца приёма (публикации) концом ленты волн; base — когда был выход (открытое, идущая публикация): у волн тогда даты.
     Своих правил нет и действуют «всем сразу» — лента начинается вопросом «Кому?» без выбранного (blank, 16.11.2026); fresh=false —
     показ уже идёт (открытое, идущая публикация): там видно, что действует. --}}
@props(['name' => 'audience_rules', 'value' => '', 'rules' => null, 'form' => null, 'inline' => false, 'deal' => false, 'sheet' => null, 'label' => 'Кому показывать', 'base' => null, 'fresh' => true])
@php
    use App\Offers\AudienceRules;
    $rules = AudienceRules::normalize($rules ?: AudienceRules::everyone());
    $options = once(fn () => AudienceRules::options()) + ['names' => AudienceRules::names($rules)];
    $sheet ??= 'audience-'.\Illuminate\Support\Str::random(6);
@endphp
<div {{ $attributes->class([$inline ? 'audience-field' : 'field']) }} data-controller="audience{{ $inline ? '' : ' sheet' }}" data-audience-options-value="{{ json_encode($options) }}"
    data-audience-effective-value="{{ json_encode($rules) }}" data-audience-deal-value="{{ $deal ? 'true' : 'false' }}"
    @if ($base) data-audience-base-value="{{ $base->toIso8601String() }}" @endif
    @if ($fresh && $value === '' && AudienceRules::isEveryone($rules)) data-audience-blank-value="true" @endif>
    <span class="field-label">{{ $label }}</span>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" @if ($form) form="{{ $form }}" @endif data-audience-target="rules">
    @if ($inline)
        <x-audience.editor :deal="$deal" :end="$end ?? null"/>
    @else
        <button type="button" class="field-input flex w-full items-center gap-3 text-left" data-action="sheet#open">
            <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
            <span class="min-w-0 flex-1" data-audience-target="summary">{{ AudienceRules::summary($rules) }}</span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </button>
        <x-ui.sheet :id="$sheet" :title="$label">
            <x-audience.editor :deal="$deal"/>
            {{-- «Сохранить» в шторке (17.10.2026, владелец: «не сохраняется, не хватает кнопки сохранить»): форма уходит сразу.
                 В чужой форме (плашка «Без подтверждений») — «Готово», сохраняет её кнопка. --}}
            @if ($form)
                <x-ui.sheet-foot type="button" foot="mt-4" data-action="audience#save sheet#close">Сохранить</x-ui.sheet-foot>
            @else
                <x-ui.sheet-foot type="button" foot="mt-4" variant="secondary" data-action="sheet#close">Готово</x-ui.sheet-foot>
            @endif
        </x-ui.sheet>
    @endif
</div>

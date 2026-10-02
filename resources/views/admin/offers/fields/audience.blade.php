{{-- Кому показывать — одной строкой-сводкой; нажатие открывает шторку с волнами этого предложения (кому, через сколько,
     «не показывать»). Правила уходят JSON-строкой в audience_rules. Своих правил нет — сводка по волнам вендора.
     $form — id формы, когда поля стоят вне её (редактор). --}}
@php
    use App\Offers\AudienceRules;
    $rules = AudienceRules::of($offer);
@endphp
<div data-controller="audience sheet" data-audience-options-value="{{ json_encode($audienceOptions) }}" data-audience-effective-value="{{ json_encode($rules) }}">
    <input type="hidden" name="audience_rules" value="{{ $offer->audience_rules ? json_encode($offer->audience_rules) : '' }}" @if ($form ?? null) form="{{ $form }}" @endif data-audience-target="rules">
    <button type="button" class="field-input flex w-full items-center gap-3 text-left" data-action="sheet#open">
        <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
        <span class="min-w-0 flex-1" data-audience-target="summary">{{ AudienceRules::summary($rules) }}</span>
        <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
    </button>
    <x-ui.sheet id="audience-{{ $offer->number }}" title="Кому показывать">
        <x-audience.editor/>
    </x-ui.sheet>
</div>

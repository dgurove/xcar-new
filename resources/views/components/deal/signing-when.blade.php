{{-- Когда покупатель сможет подписать договор — день и время (`Offers\Signing`, 11.11.2026, Альфа: «напишите желаемую дату и
     время»). Пока поставщик готовит договор: не названо — форма плитками; названо — строкой «Покупатель может чт 12.11 в 15:00»
     с «Изменить» (шторка с той же формой). На шаге выбора без времени поставщика — форма, «Записать». `action` — POST (сайт или
     CRM), `back` — куда вернуться (чат). --}}
@props(['deal', 'action', 'back' => null, 'picks' => false])
@php
    $at = $picks ? null : \App\Offers\Signing::wishAt($deal);
    $office = \App\Offers\Signing::office($deal);
@endphp
<div {{ $attributes }}>
    @if ($at)
        <div data-controller="sheet">
            <button type="button" class="info-row w-full text-left" data-action="sheet#open">
                <x-ui.icon name="calendar" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">Покупатель может {{ \App\Offers\Signing::short($at) }}</span>
                <span class="text-sm text-ink-muted">Изменить</span>
            </button>
            <x-ui.sheet :id="'signing-when-'.$deal->id" title="Когда покупатель сможет подписать">
                @include('cabinet.deals.signing-when-form', ['inSheet' => true])
            </x-ui.sheet>
        </div>
    @else
        @include('cabinet.deals.signing-when-form', ['inSheet' => false, 'submit' => $picks ? 'Записать' : 'Отправить'])
        @if ($office['address'] || $office['by_appointment'])
            <p class="mt-2 text-sm text-ink-muted">{{ $office['address'] ? $office['address'].', ' : '' }}{{ preg_replace('/^0(\d)/', '$1', $office['from']) }}–{{ $office['to'] }}{{ $office['by_appointment'] ? ', по записи' : '' }}</p>
        @endif
    @endif
</div>

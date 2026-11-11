{{-- Когда покупатель сможет подписать договор (`Offers\Signing`, 11.11.2026): ближайшие рабочие дни филиала плитками с галкой
     и часть дня сегментом — ответ встаёт в письмо поставщику «время для подписания». Ответил — строкой с «Изменить» (шторка
     с той же формой). `action` — адрес POST (сайт или CRM). --}}
@props(['deal', 'action'])
@php
    $wish = \App\Offers\Signing::wishLine($deal);
    $picked = collect($deal->signing_wish['days'] ?? []);
    $days = \App\Offers\Signing::workdays($deal)->concat(\App\Offers\Signing::wishDays($deal))->unique(fn ($d) => $d->toDateString())->sortBy(fn ($d) => $d->getTimestamp())->values();
    $sheet = 'signing-days-'.$deal->id;
    $office = \App\Offers\Signing::office($deal);
@endphp
<div {{ $attributes }}>
    @if ($wish)
        <div data-controller="sheet">
            <button type="button" class="info-row w-full text-left" data-action="sheet#open">
                <x-ui.icon name="calendar" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">Покупатель может {{ $wish }}</span>
                <span class="text-sm text-ink-muted">Изменить</span>
            </button>
            <x-ui.sheet :id="$sheet" title="Когда покупатель сможет подписать">@include('cabinet.deals.signing-days-form', ['inSheet' => true])</x-ui.sheet>
        </div>
    @else
        @include('cabinet.deals.signing-days-form', ['inSheet' => false])
    @endif
</div>

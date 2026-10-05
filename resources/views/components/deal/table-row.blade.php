{{-- Строка таблицы сделок CRM, по образцу предложений: иконка типа ТС и название, под ним на телефоне логотип вендора с
     номером убытка (копируется), номер предложения и чей ход с часами; от 640 они своими столбцами — вендор с убытком,
     №, менеджер, этап (просрочен — оранжевым) с часами. Справа сумма, на телефоне под ней этап словом. Нажатие —
     карточка сделки рядом (x-ui.row-link). --}}
@props(['deal'])
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    [$word, $tone] = match (true) {
        ! $active => [$deal->state->label(), $deal->state === DealState::Done ? 'text-accent-text' : 'text-danger'],
        (bool) $position => [$position->stage->block?->name ?? $position->stage->name, $position->isOverdue() ? 'text-urgent' : ''],
        default => [$offer->state->label(), 'text-ink-muted'],
    };
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    <td class="grow">
        <x-ui.row-link :key="$deal->id"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub" data-controller="fitline">
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/><span>№ {{ $offer->number }}</span></span>
        </span>
        @if ($active && $position)<x-route.clock :position="$position" side="staff" class="cell-sub sm:hidden"/>@endif
    </td>
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $offer->number }}</td>
    <td class="hidden sm:table-cell">@if ($deal->buyer)<span class="flex items-center gap-2"><x-ui.avatar :user="$deal->buyer" :size="22"/><span class="truncate">{{ $deal->buyer->name }}</span></span>@endif</td>
    <td class="hidden sm:table-cell">
        <span class="{{ $tone }}">{{ $word }}</span>
        @if ($active && $position)<x-route.clock :position="$position" side="staff" class="cell-sub"/>@endif
    </td>
    <td class="num nums">
        {{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}
        <span class="cell-sub ml-auto max-w-36 !whitespace-normal sm:hidden {{ $tone }}">{{ $word }}</span>
    </td>
</tr>

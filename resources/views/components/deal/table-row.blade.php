{{-- Строка таблицы сделок CRM — одной линией (13.10.2026, владелец: «таблица, а не эссе»): иконка типа ТС и название, на
     телефоне под ним логотип вендора с номером убытка (копируется) и срок шага; от 640 они своими столбцами —
     вендор с убытком, менеджер аватаром (имя и № — от 1280), этап (горит — оранжевым), срок коротким словом (`x-route.term`). Справа сумма, на
     телефоне под ней этап словом. Кто ждёт и что с письмом — фразой в карточке сделки рядом (x-ui.row-link). --}}
@props(['deal'])
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    [$word, $tone] = match (true) {
        ! $active => [$deal->state->label(), $deal->state === DealState::Done ? 'text-accent-text' : 'text-danger'],
        // Письмо поставщику не ушло или он ответил — ход наш, этап горит, как просроченный (11.10.2026).
        (bool) $position => [$position->stage->block?->name ?? $position->stage->name, $position->isOverdue() || in_array($position->letterStatus(), ['unsent', 'replied'], true) ? 'text-urgent' : ''],
        default => [$offer->crmTab(), 'text-ink-muted'],
    };
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    <td class="grow">
        <x-ui.row-link :key="$deal->id"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub" data-controller="fitline">
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/>@if ($active && $position)<x-route.term :position="$position"/>@endif</span>
        </span>
    </td>
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    <td class="cell-dim nums hidden xl:table-cell">{{ $offer->number }}</td>
    <td class="hidden sm:table-cell">@if ($deal->buyer)<span class="flex items-center gap-2" title="{{ $deal->buyer->name }}"><x-ui.avatar :user="$deal->buyer" :size="22"/><span class="hidden truncate xl:inline">{{ $deal->buyer->name }}</span></span>@endif</td>
    <td class="hidden sm:table-cell"><span class="block max-w-52 truncate {{ $tone }}">{{ $word }}</span></td>
    <td class="hidden sm:table-cell">@if ($active && $position)<x-route.term :position="$position"/>@endif</td>
    <td class="num nums">
        {{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}
        <x-offer.margin :m="$deal->marginMark()" class="cell-sub !ml-0"/>
        <span class="cell-sub ml-auto max-w-40 truncate sm:hidden {{ $tone }}">{{ $word }}</span>
    </td>
</tr>

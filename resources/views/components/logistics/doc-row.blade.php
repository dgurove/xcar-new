{{-- Строка «Документов» (модератору и админу): машина, под ней где документы (ждут отправки — пункт СДЭК оранжевым, в пути,
     получены), вендор с номером убытка, кому — исполнитель чипом и его город, справа когда назначен или
     отправлены — датой и временем. Та же строка приходит в ответе карточки (`TransportController::detail`, `documents`). --}}
@props(['t'])
@php
    $u = $t->executor;
    $at = $t->docs_sent_at ?? $t->assigned_at;
@endphp
<tr data-detail-key="{{ $t->id }}" id="doc-{{ $t->id }}" data-search-row>
    <td class="grow">
        <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span><x-car.keys :count="$t->car?->keys_count"/></span></x-ui.row-link>
        <span class="cell-sub">
            <span class="sm:hidden"><x-vendor.ref :vendor-id="$t->vendorId()" :ref="$t->car?->claim_ref"/></span>
            @if ($t->docs_received_at)<span class="text-ink-muted">получены</span>@elseif ($t->docs_track)<span class="text-accent-text">в пути</span>@else<span class="text-urgent">{{ $u?->cdek_point ? 'СДЭК: '.$u->cdek_point : 'пункта СДЭК нет' }}</span>@endif
            @if ($u)<span class="sm:hidden">{{ $u->shortName() }}</span>@endif
        </span>
    </td>
    <td class="hidden sm:table-cell"><x-vendor.ref :vendor-id="$t->vendorId()" :ref="$t->car?->claim_ref" cell/></td>
    <td class="hidden sm:table-cell">@if ($u)<span class="inline-flex items-center gap-2"><x-ui.person :user="$u"/>@if ($u->logistics_city)<span class="text-ink-muted">{{ $u->logistics_city }}</span>@endif</span>@endif</td>
    <td class="num nums whitespace-nowrap cell-dim">{{ \App\Support\Ago::stamp($at, time: true) }}</td>
</tr>

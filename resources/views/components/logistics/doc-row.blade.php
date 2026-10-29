{{-- Строка «Документов» (модератору и админу): машина, где документы (ждут отправки — пункт СДЭК оранжевым, в пути с
     трек-номером, получены; от 640 — своим столбцом, на телефоне — под названием), вендор с номером убытка, кому — исполнитель чипом и его город, справа когда назначен или
     отправлены — датой и временем. Столбцы — `DocumentController::table`. --}}
@props(['t', 'table'])
@php
    $u = $t->executor;
    $at = $t->docs_sent_at ?? $t->assigned_at;
@endphp
<tr data-detail-key="{{ $t->id }}" id="doc-{{ $t->id }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span><x-car.keys :count="$t->car?->keys_count"/></span></x-ui.row-link>
        <span class="cell-sub">
            <span class="sm:hidden"><x-vendor.ref :vendor-id="$t->vendorId()" :ref="$t->car?->claim_ref"/></span>
            <span class="sm:hidden">@if ($t->docs_received_at)<span class="text-ink-muted">получены</span>@elseif ($t->docs_track)<span class="text-accent-text">в пути</span>@else<span class="text-urgent">{{ $u?->cdek_point ? 'СДЭК: '.$u->cdek_point : 'пункта СДЭК нет' }}</span>@endif</span>
            @if ($u)<span class="sm:hidden">{{ $u->shortName() }}</span>@endif
        </span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$t->vendorId()" :ref="$t->car?->claim_ref" cell/></td>
        @break
        @case('docs')
    <td class="{{ $col->classes() }}">@if ($t->docs_received_at)<span class="text-ink-muted">получены</span>@elseif ($t->docs_track)<span class="text-accent-text">в пути</span> <x-ui.copy-code :value="$t->docs_track" done="Трек в буфере"/>@else<span class="text-urgent">{{ $u?->cdek_point ? 'ждут отправки в СДЭК: '.$u->cdek_point : 'пункта СДЭК нет' }}</span>@endif</td>
        @break
        @case('to')
    <td class="{{ $col->classes() }}">@if ($u)<span class="inline-flex items-center gap-2"><x-ui.person :user="$u"/>@if ($u->logistics_city)<span class="text-ink-muted">{{ $u->logistics_city }}</span>@endif</span>@endif</td>
        @break
        @case('at')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::cell($at, time: true) }}</td>
        @break
        @endswitch
    @endforeach
</tr>

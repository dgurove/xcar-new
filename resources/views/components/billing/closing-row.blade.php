{{-- Строка «Счетов и актов» (`ClosingDoc`) — строка списка плашкой, тем же видом, что «Вендоры» (06.11.2026, владелец: «как в
     вендорах, тот же модуль»): логотип, имя плательщика, вторым этажом — за что (хранение не пишется — почти все такие), сколько
     строк в акте, состояние словом своим цветом («35 строк отправить»), у отправленного — номер; справа сумма. Нажатие — страница документа.
     Без плательщика (покупатель без контрагента) — строка без ссылки: документа не из чего собрать. --}}
@props(['doc'])
@php
    use App\Support\Money; use App\Support\Plural;
    $d = $doc;
    $link = $d->state !== 'nobody';
    $tag = $link ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($link) href="/money/closing/{{ $d->key }}" @endif id="doc-{{ $d->key }}" class="row gap-2.5 pl-3 pr-2.5">
    @if ($d->vendor)<x-vendor.logo :vendor="$d->vendor" class="size-8"/>@else<span class="vendor-logo is-letter size-8" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($d->name, 0, 1)) }}</span></span>@endif
    <span class="min-w-0 flex-1">
        {{-- Имя переносится по словам, а не режется: «АльфаСтрахование С…» на телефоне теряло «СПб» — другой плательщик. --}}
        <span class="block">{{ $d->vendor?->name ?? $d->name }}</span>
        <span class="row-sub">
            @if ($d->what !== 'хранение')<span>{{ $d->what }}</span>@endif
            @if (in_array($d->what, ['хранение', 'эвакуация', 'страхователь'], true))<span class="nums">{{ $d->cars }}&nbsp;{{ Plural::of($d->cars, ['строка', 'строки', 'строк']) }}</span>@endif
            <span class="{{ $d->tone() }}">{{ $d->word() }}</span>
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}</span>@endif
            @if ($d->drift)<span class="nums">было {{ Money::rub($d->drift['was']) }}</span>@endif
        </span>
    </span>
    <span class="nums shrink-0">{{ Money::rub($d->drift['now'] ?? $d->amount) }}</span>
    @if ($link)<x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>@endif
</{{ $tag }}>

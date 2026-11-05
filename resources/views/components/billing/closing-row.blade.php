{{-- Строка «Счетов и актов» (`ClosingDoc`) в блоке месяца `.list` (06.11.2026, владелец: «табличный вид не подходит — блоки
     со строками»): логотип и имя плательщика, под ним что за документ и сколько ТС (у отправленного — номер); справа столбиком
     сумма и под ней состояние своим цветом; нажатие — страница документа. Файлов в строке нет (владелец: «бесполезные»).
     Без плательщика (покупатель без контрагента) — строка без ссылки: документа не из чего собрать. --}}
@props(['doc'])
@php
    use App\Support\Money; use App\Support\Ago;
    $d = $doc;
    $sub = $d->what.(in_array($d->what, ['хранение', 'эвакуация', 'страхователь'], true) ? ', '.$d->cars.' ТС' : '');
    $tag = $d->state === 'nobody' ? 'div' : 'a';
@endphp
<{{ $tag }} @if ($tag === 'a') href="/money/closing/{{ $d->key }}" @endif id="doc-{{ $d->key }}" class="row doc-row">
    @if ($d->vendor)<x-vendor.logo :vendor="$d->vendor" class="size-9 shrink-0"/>@else<span class="vendor-logo is-letter size-9 shrink-0" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($d->name, 0, 1)) }}</span></span>@endif
    <div class="min-w-0 flex-1">
        <div class="doc-row-name">{{ $d->vendor?->name ?? $d->name }}</div>
        <div class="row-sub">
            <span>{{ $sub }}</span>
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}@if ($d->invoice->issued_at) от {{ Ago::day($d->invoice->issued_at) }}@endif</span>@endif
            @if ($d->extra)<span>дополнительно</span>@endif
            @if ($d->drift)<span class="nums">было {{ Money::nums($d->drift['was']) }}</span>@endif
        </div>
    </div>
    <div class="doc-row-end">
        <span class="nums">{{ Money::rub($d->drift['now'] ?? $d->amount) }}</span>
        <span class="{{ $d->tone() }}">{{ $d->word() }}</span>
    </div>
    @if ($tag === 'a')<x-ui.chevron/>@endif
</{{ $tag }}>

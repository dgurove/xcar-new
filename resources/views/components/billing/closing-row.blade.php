{{-- Строка «Счетов и актов» (`ClosingDoc`) в блоке месяца `.list` (06.11.2026, владелец: «табличный вид не подходит — блоки
     со строками»; «на компе кнопки счёта и акта, на телефоне — только внутри; красивые и ненавязчивые»): маленький логотип перед именем
     плательщика (как у номера убытка; квадрат 36 px съедал имя на телефоне), под ним что за документ и сколько ТС (у отправленного — номер); от 1024 перед суммой файлы тихими метками
     (значок типа и «Счёт» / «Акт»: нажатие — шторка документов, вкладки — файлы этой строки); справа столбиком сумма и под ней
     состояние своим цветом. Строка — ссылка-растяжка на страницу документа (ссылку в ссылку не положить), файлы над ней.
     Без плательщика (покупатель без контрагента) — строка без ссылки: документа не из чего собрать. --}}
@props(['doc'])
@php
    use App\Support\Money; use App\Support\Ago;
    $d = $doc;
    $sub = $d->what.(in_array($d->what, ['хранение', 'эвакуация', 'страхователь'], true) ? ', '.$d->cars.' ТС' : '');
    $link = $d->state !== 'nobody';
    $files = $d->files();
@endphp
<div id="doc-{{ $d->key }}" class="row doc-row">
    <div class="min-w-0 flex-1">
        @if ($link)
            <a href="/money/closing/{{ $d->key }}" class="doc-row-link doc-row-name">@if ($d->vendor)<x-vendor.logo :vendor="$d->vendor"/>@endif{{ $d->vendor?->name ?? $d->name }}</a>
        @else
            <div class="doc-row-name">{{ $d->name }}</div>
        @endif
        <div class="row-sub">
            <span>{{ $sub }}</span>
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}@if ($d->invoice->issued_at) от {{ Ago::day($d->invoice->issued_at) }}@endif</span>@endif
            @if ($d->extra)<span>дополнительно</span>@endif
            @if ($d->drift)<span class="nums">было {{ Money::nums($d->drift['was']) }}</span>@endif
        </div>
    </div>
    @if ($files)
        <div class="doc-row-files max-lg:hidden" data-docs-scope>
            @foreach ($files as $f)
                <x-ui.doc :doc="$f" class="doc-row-file"><x-ui.file-icon :name="$f['name']"/>{{ $f['label'] }}</x-ui.doc>
            @endforeach
        </div>
    @endif
    <div class="doc-row-end">
        <span class="nums">{{ Money::rub($d->drift['now'] ?? $d->amount) }}</span>
        <span class="{{ $d->tone() }}">{{ $d->word() }}</span>
    </div>
    @if ($link)<x-ui.chevron/>@else<span class="size-5 shrink-0" aria-hidden="true"></span>@endif
</div>

{{-- Правый верх строки предложения на телефоне (`num-meta`, на ПК её нет): только дата — когда заведено или попало во вкладку,
     без аватара того, кто завёл (15.11.2026, владелец). Горит срок обязывающего — вместо даты он: «⏱ 11.11» красным (нет
     покупателя, поставщику не заплачено), оранжевым (подтверждение не принято); у названия на телефоне значка тогда нет.
     binding=false — у вкладки свой столбец «Срок». always — срок всегда, пока он что-то значит (`Binding::matters`), датой и
     временем, серым, когда не горит («Все», 17.11.2026, владелец: «справа дату и время обязывающего с иконкой»). --}}
@props(['offer', 'when' => null, 'binding' => true, 'always' => false])
@php
    $level = ($binding || $always) && $offer->binding_until && \App\Offers\Binding::matters($offer) ? \App\Offers\Binding::of($offer) : null;
    $hot = in_array($level, ['panic', 'over', 'deal', 'undecided'], true);
    $bat = $hot || ($always && $level) ? $offer->binding_until->copy()->timezone(config('app.timezone')) : null;
@endphp
@if ($always)
@if ($bat)
<span @class(['num-meta sm:hidden', 'text-urgent' => $level === 'undecided', 'text-danger' => $hot && $level !== 'undecided']) title="Срок обязывающего предложения {{ $bat->format('d.m.Y H:i') }}"><span class="meta-ico"><x-ui.icon name="timer" class="size-3.5"/></span><span class="nums">{{ \App\Offers\Binding::html($bat) }}</span></span>
@endif
@elseif ($hot)
<span @class(['num-meta sm:hidden', 'text-urgent' => $level === 'undecided', 'text-danger' => $level !== 'undecided']) title="Срок обязывающего предложения {{ $bat->format('d.m.Y H:i') }}"><span class="meta-ico"><x-ui.icon name="timer" class="size-3.5"/></span><span class="nums">{{ $bat->format($bat->isCurrentYear() ? 'd.m' : 'd.m.Y') }}</span></span>
@elseif ($when)
<span class="num-meta sm:hidden">{{ \App\Support\Ago::html($when, short: true) }}</span>
@endif

{{-- Строка таблицы «Из писем»: ТС и последнее письмо словами, убыток, VIN, вендор, сколько писем, когда последнее, срок ответа. Нажатие — окошко. --}}
@props(['c', 'base'])
@php
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $last = $c->lastLetter();
    $href = $base.'/'.$c->id;
    $by = $v('answer_by') ? \Illuminate\Support\Carbon::parse($v('answer_by'))->timezone('Europe/Moscow') : null;
@endphp
<tr id="candidate-{{ $c->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}/peek" tabindex="0" class="{{ $c->messages->contains(fn ($m) => ! $m->is_seen && ! $m->isOurs()) ? 'font-medium' : '' }}">
    <td class="grow"><span class="{{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</span>@if ($v('plate')) <span class="nums hidden text-ink-muted sm:inline">{{ $v('plate') }}</span>@endif
        <span class="block max-w-[28rem] truncate text-xs font-normal text-ink-muted">{{ $last ? \App\Mail\Chains\NodeTitle::for($last, $c) : '' }}</span></td>
    <td class="nums text-[11px] tracking-tighter text-ink-dim sm:text-[13px] sm:tracking-normal">{{ $c->code }}</td>
    <td class="nums hidden text-[11px] text-ink-dim sm:table-cell">{{ $v('vin') }}</td>
    <td class="hidden text-ink-dim sm:table-cell">{{ $c->vendor?->name ?? $v('vendor') }}</td>
    <td class="num nums">{{ $c->messages_count }}</td>
    <td class="num nums text-ink-dim">{{ ($c->last_message_at ?? $c->created_at)->translatedFormat('j M') }}</td>
    <td class="num nums hidden sm:table-cell {{ $by?->isPast() ? 'text-danger' : '' }}">{{ $by?->translatedFormat('j M H:i') }}</td>
</tr>

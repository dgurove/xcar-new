{{-- Строка таблицы заявок: тип точкой по срочности, ТС, номер убытка (копируется), срок, исполнитель, парковка.
     Нажатие — окошко; пересказа «что делать» под названием нет, его говорят тип и срок. --}}
@props(['req'])
@php $v = $req->vehicle; $href = '/cars/'.$v->id; $dot = $req->isOverdue() ? 'dot-urgent' : ($req->isOpen() ? 'dot-open' : ''); @endphp
<tr id="request-{{ $req->id }}" data-peek-url="/requests/{{ $req->id }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $req->isOverdue() ? 'text-danger' : '' }}">
    <td class="text-[11px] sm:text-[13px]"><span class="dot {{ $dot }}"></span>{{ $req->type->label() }}</td>
    <td class="grow">{{ $v->titleWithYear() }}@if ($v->plate) <span class="nums hidden text-ink-muted sm:inline">{{ $v->plate }}</span>@endif</td>
    <td class="nums hidden text-[11px] text-ink-dim sm:table-cell">@if ($v->ref)<x-ui.copy-code :value="$v->ref"/>@endif</td>
    <td class="num nums text-ink-dim">{{ $req->planned_at?->translatedFormat('j M, H:i') }}</td>
    <td class="hidden text-ink-dim sm:table-cell">{{ $req->assignee?->name }}</td>
    <td class="hidden text-ink-dim sm:table-cell"><x-ui.place>{{ ($req->yard ?? $v->yard)?->name }}</x-ui.place></td>
</tr>

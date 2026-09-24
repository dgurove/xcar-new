{{-- Строка таблицы заявок. Ячейка ТС в два этажа: название, под ним тип словом (красным при просрочке),
     «позвонить», госномер и номер убытка; от 640 тип и номер уходят в свои столбцы. Справа срок: день, под
     ним время. Пересказа «что делать» нет — его говорят тип и срок. Нажатие — окошко. --}}
@props(['req'])
@php
    $v = $req->vehicle;
    $href = '/cars/'.$v->id;
    $late = $req->isOverdue();
@endphp
<tr id="request-{{ $req->id }}" data-peek-url="/requests/{{ $req->id }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $v->titleWithYear() }}</span>
        <span class="cell-sub">
            <span class="sm:hidden {{ $late ? 'text-danger' : 'text-ink' }}">{{ mb_strtolower($req->type->label()) }}</span>
            @if ($req->needsCall())<span class="text-urgent">позвонить</span>@endif
            @if ($v->plate)<span>{{ $v->plate }}</span>@endif
            @if ($v->ref)<span class="sm:hidden">{{ $v->ref }}</span>@endif
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">@if ($v->ref)<x-ui.copy-code :value="$v->ref"/>@endif</td>
    <td class="hidden sm:table-cell {{ $late ? 'text-danger' : '' }}">{{ $req->type->label() }}</td>
    <td class="num nums">
        @if ($req->planned_at)
            <span class="block sm:inline {{ $late ? 'text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M') }}</span>
            <span class="cell-sub sm:ml-1 sm:inline">{{ $req->planned_at->format('H:i') }}</span>
        @endif
    </td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell">{{ $req->assignee?->name }}</td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell">{{ ($req->yard ?? $v->yard)?->name }}</td>
</tr>
